<?php

namespace App\Classes;

class Calculator
{

    protected string $from;
    protected string $to;
    protected string $symbol;
    protected float $count;
    protected array $variants;

    /**
     * Точность чисел
     */
    const PRECISION = 8;

    /**
     * Сеттер исходных данных
     * @param string $from Из какой монеты конвертируем
     * @param string $to В какую монету конвертируем
     * @param float $count Количество, которое хотим обменять
     * @return void
     */
    public function setExchangeData(string $from, string $to, float $count):void
    {
        $this->from = $from;
        $this->to = $to;
        $this->count = $count;
        $binance = new Binance();
        $tickers = $binance->getBinanceTickers();
        $symbol = $from.'/'.$to;
        $this->symbol = (array_key_exists($symbol, $tickers['data'])) ? $symbol : $to.'/'.$from;

    }

    /**
     * Сеттер доступных вариантов для конвертации
     * @param array $variants Массив доступных вариантов
     * @return void
     */
    public function setVariants(array $variants):void
    {
        $this->variants = $variants;
    }

    /**
     * @return array Массив с результатами калькуляции
     */
    public function getPrice(): array
    {
        $array = [];
        $data = config('app.prices_array');
        $orderBookArray = [];
        $binance = new Binance();

        // Можно обменять напрямую
        if($this->variants['level0']['status']){
            $orderBook = $binance->getOrderBook($this->symbol);
            $orderBookArray[$this->symbol] = $orderBook;
            $data['level0']['status'] = true;
            $data['level0']['data'] = $this->getTransactionsSum($orderBook, $this->symbol);

        // Можно обменять через 1 промежуточный коин
        }elseif( $this->variants['level1']['status']){

            foreach ($this->variants['level1']['data']['fromToGap'] as $symbol){
                $orderBook = $binance->getOrderBook($symbol);
                $arrayKey = $this->makeToKey($symbol);
                $orderBookArray[$arrayKey] = $orderBook;
                $array['fromToGap'][$arrayKey] = $this->getTransactionsSum($orderBook, $symbol);
            }
            
            foreach ($this->variants['level1']['data']['gapToEnd'] as $symbol){
                $orderBook = $binance->getOrderBook($symbol);
                $arrayKey = $this->makeFromKey($symbol);
                $orderBookArray[$arrayKey] = $orderBook;
                $array['gapToEnd'][$arrayKey] = $this->getTransactionsSum($orderBook, $symbol, $array['fromToGap'][$arrayKey], $arrayKey);
            }
            $data['level1']['status'] = true;
            $data['level1']['data'] = $array['gapToEnd'];

        // Можно обменять через 2 промежуточных коина
        // Не уверен, что такие ситуации есть. Будет очень прожорливая для расчета операция
        }else{
            //TODO: Написать расчет для 2х посредников
        }

        return $data;
    }

    /**
     * @param string $symbol Символ тикера
     * @return string Символ коина, в который конвертируется
     */
    private function makeToKey(string $symbol): string
    {
        return str_replace($this->from.'/', '', str_replace('/'.$this->from, '', $symbol));
    }

    /**
     * @param string $symbol Символ тикера
     * @return string Символ коина, из которого конвертируется
     */
    private function makeFromKey(string $symbol): string
    {
        return str_replace($this->to.'/', '', str_replace('/'.$this->to, '', $symbol));
    }

    /**
     * @param array $orderBook Массив заявок (стакан)
     * @param string $symbol Символ тикера
     * @param array|null $oldData Данные предыдущих этапов конвертации
     * @param string|null $from Из какого коина конвертируем
     * @return array Массив с расчетами конвертации
     */
    private function getTransactionsSum(array $orderBook, string $symbol, array $oldData = null, string $from = null): array
    {
        $spend = 0;
        $convertToSum = 0;
        $history = [];
        $volumeFailed = true;
        $calculation = [];

        if(is_null($oldData)){

            $from = $this->from;
            $forSell = $this->count;
            $transactions = 0;
            $marketFEE = [];
            $volume = 'success';

        }else{
            
            $forSell = $oldData['convertToSum'];
            $transactions = $oldData['transactions'];
            $marketFEE[ $oldData['symbol'] ] = $oldData['marketFEE'][ $oldData['symbol'] ];
            $volume = $oldData['volume'];
            $history = $oldData['history'];

        }

        // Проверяем, обратная ли конвертация
        $isInvert = $this->isInvert($symbol, $from);
        $realFromSym = $from;
        $realToSym = $this->getToSymbol($symbol, $realFromSym);

        if($isInvert){
            $tickerFromSim = $realToSym;
            $tickerToSim = $realFromSym;
        }else{
            $tickerFromSim = $realFromSym;
            $tickerToSim = $realToSym;
        }


        $marketFEE[$symbol] = ['sum' => 0, 'symbol' => ''];
        $orders = $orderBook[$symbol]['bids'];

        // Учет ситуации, когда у нас обратная конвертация. Прим: символ ETH/BTC, а конвертируем BTC->ETH
        // Тогда расчет делаем не по покупкам, а по продажам
        if ($isInvert){
            $orders = $orderBook[$symbol]['asks'];
        }
        

        foreach ($orders as $order){

            /*
             * $order Example
             * 0.05 BTC -> USDT
             * $symbol = USDT/BTC
             */
            $orderPrice = $order[0];
            $orderCount = $order[1];
            $orderPriceReal = $orderPrice;

            if ($isInvert){
                $orderPrice = 1/$orderPrice;
                $orderCount = $orderCount*$orderPriceReal;
                $orderPriceReal = 1/$orderPrice;
            }

            // Сколько максимум BTC мы можем получить на заявку
            $orderMax = $orderPriceReal*$orderCount;

            // Комиссия биржи
            $orderFeeMax = $orderCount * Binance::getFee(); // BTC

            // Округляем в большую сторону на 6м знаке после точки
            $orderFeeMax = $this->round_up($orderFeeMax); // 0.0007745913617 -> 0.000774592

            // Инкремент числа транзакций
            $transactions++;

            $arrival = $forSell - $spend;

            // Проверка, нужна ли нам вся заявка
            $need = $forSell - $spend - $orderCount - $orderFeeMax; // USDT
            $needCalc = [$forSell, $spend, $orderCount, $orderFeeMax];
            if($need > 0){
                $iterationSpend = $orderCount;
                $iterationFee = $orderFeeMax;
                $needAllOrder = true;
                $spendIncrement = $iterationSpend + $orderFeeMax;
                $convertToSumIncrement = $orderPrice*$orderCount;
            }else{
                $iterationSpend = $forSell - $spend;
                $iterationFee = $this->round_up($iterationSpend * Binance::getFee());
                $needAllOrder = false;
                $spendIncrement = $iterationSpend;
                $iterationSpend -= $iterationFee;
                $convertToSumIncrement = $iterationSpend*$orderPrice;
            }

            $spend += $this->round_up($spendIncrement);
            $convertToSum += $this->round_down($convertToSumIncrement); //BTC
            // Заносим в массив для дальнейшего вывода суммы комиссии
            $marketFEE[$symbol]['sum'] += $iterationFee;
            $marketFEE[$symbol]['symbol'] = $from;

            $calculation[] = [
                'orderPrice' => $orderPrice,
                'orderPriceFormated' => $this->formatPrice($orderPrice),
                'orderCount' => $orderCount,
                'orderCountFormated' => $this->formatPrice($orderCount),
                'orderMax' => $orderMax,
                'orderPriceReal' => $orderPriceReal,
                'orderPriceRealFormated' => $this->formatPrice($orderPriceReal),
                'arrival' => $arrival,
                'arrivalFormated' => $this->formatPrice($arrival),
                'iterationFee' => $iterationFee,
                'iterationSpend' => $iterationSpend,
                'iterationSpendFormated' => $this->formatPrice($iterationSpend),
                'need' => $need,
                'needCalc' => $needCalc,
                'spend' => $spend,
                'isInvert' => $isInvert,
                'needAllOrder' => $needAllOrder,
                'forSell' => $forSell,
                'convertToSum' => $convertToSum,
                'realFromSym' => $realFromSym,
                'realToSym' => $realToSym,
                'tickerFromSim' => $tickerFromSim,
                'tickerToSim' => $tickerToSim
            ];

            // Выходим из цикла, если ликвидности достаточно и сможем потратить всю исходную сумму
            if(!$needAllOrder){
                $volumeFailed = false;
                break;
            }

        }

        // Если объема в *Binance::ORDER_BOOK_LIMIT* (100) ордеров нам не достаточно, обменять не сможем.
        // Делать более 100, пожалуй, коммерческого смысла нет
        if( $volumeFailed )
            $volume = 'fail';

        // Пушим логи
        array_push($history, [
            $symbol => [
                'from' => $from,
                'calculation'=> $calculation,
                'transactions' => $transactions,
                'convertToSum' => $convertToSum,
                'spend' => $spend,
                'fee' => $marketFEE[$symbol]['sum']
            ]
        ] );
        $convertToSumOld = $convertToSum;


        $convertToSum = $this->formatPrice($convertToSum);

        return [
            'volume' => $volume,
            'transactions' => $transactions,
            'spend' => is_null($oldData) ? $spend : $this->formatPrice($spend),
            'convertToSum' => is_null($oldData) ? $convertToSum : $this->formatPrice($convertToSum),
            'convertToSumOld' => is_null($oldData) ? $convertToSumOld : $this->formatPrice($convertToSumOld),
            'isInvert' => $isInvert,
            'forSell' => $forSell,
            'marketFEE' => $marketFEE,
            'symbol' => $symbol,
            'history' => $history
        ];

    }

    /**
     * @param string $symbol Символ тикера
     * @return string Символ, в который конвертируем
     */
    private function getToSymbol(string $symbol, string $from):string
    {
        if( str_starts_with($symbol, $from) ){
            return str_replace($from.'/', '',$symbol);
        }else{
            return str_replace('/'.$from, '',$symbol);
        }
    }


    /**
     * @param float $number Стоимость
     * @return float Форматированная стоимость
     */
    private function formatPrice(float $number): float
    {
        $precision = self::PRECISION;
        $separator = '.';
        $numberParts = explode($separator, $number);
        $response = $numberParts[0];
        if (count($numberParts)>1 && $precision > 0) {
            $response .= $separator;
            $response .= substr($numberParts[1], 0, $precision);
        }
        return $response;
    }

    /**
     * @param string $symbol Символ тикера
     * @param string $from В какой коин пытаемся конвертировать
     * @return bool В прямом или обратном порядке идет конвертация
     */
    private function isInvert(string $symbol, string $from): bool
    {
        return !str_starts_with($symbol, $from);
    }

    /**
     * @param float $number Сумма
     * @return float Округленная сумма
     */
    private function round_up(float $number): float
    {
        return  round($number, self::PRECISION);
    }

    /**
     * @param float $number Сумма
     * @return float Округленная сумма
     */
    private function round_down(float $number): float
    {
        return  round($number, self::PRECISION, PHP_ROUND_HALF_DOWN);
    }
}