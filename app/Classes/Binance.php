<?php

/**
 * Класс для работы с API Binance
 * PHP examples https://github.com/ccxt/ccxt/tree/master/examples/php
 */

namespace App\Classes;
use \ccxt\binance as BinanceExchange;
use Illuminate\Support\Facades\Cache;
use Exception;
use ccxt\exchangeError;
use ccxt\eetworkError;

class Binance
{

    /**
     * Комиссия в Binance
     */
    const FEE = 0.001;

    /**
     * Время жизни кэша, в который складываются ответы от Binance
     */
    const UPDATE_TIME_MINUTES = 2;

    /**
     * Лимит на размер получаемого стакана из Binance
     */
    const ORDER_BOOK_LIMIT = 100;

    const FIAT_SYMBOLS = ['AUD', 'BIDR', 'BRL', 'BUSD', 'EUR', 'GBP', 'RUB', 'TRY', 'TUSD', 'UAH', 'USDC', 'USDP', 'USDT'];

    public function __construct()
    {
        date_default_timezone_set('UTC');
    }

    /**
     * @return float Размер комиссии биржи
     */
    static public function getFee(): float
    {
        //TODO: сделать динамическую, не статическую сумму комиссии Бинанса
        return self::FEE;
    }

    /**
     * @return array Строит массив с тикерами и данными по ним
     */
    public function buildTickersToTickersArray(): array
    {
        $array = [];
        $tickers = $this->getBinanceTickers()['data'];
        foreach ($tickers as $tickerToTickerKey => $value){
            if($value['bidVolume'] > 0)
                $array[$tickerToTickerKey] = $value;
        }
        ksort($array);
        return $array;
    }

    /**
     * @return array|void Возвращает массив с тикерами, который предоставляет Binance
     * @throws string Описания ошибок. Обычно траблы с подключением или сетью
     */
    public function getBinanceTickers(){

        // Проверяем, прошло ли 2 минуты, чтобы не напороться на бан
        $checkPossibility = $this->canCheckBinanceFetchTickersTimeOut();
        if(!$checkPossibility['status'])
            return ['success' => false, 'data' => 'Слишком часто запрашивается контент'];

        $cacheKey = 'fetchTickersList';
        $tickers = Cache::get($cacheKey);
        if(!is_null($tickers)) {
            return ['success' => true, 'data' => $tickers];
        }else{
            $exchange = new BinanceExchange(array(
                'timeout' => 30000,
            ));
            try {
                $tickers = $exchange->fetch_tickers();
                if(is_array($tickers)){

                    // Навешиваю кэш, чтобы не забанил Бинанс
                    Cache::put($cacheKey, $tickers, 60*60*24); // 1 day
                    return ['success' => true, 'data' => $tickers];
                }else{
                    report('Не удалось получить список тикеров');
                }
            } catch (NetworkError $e) {
                echo '[Network Error] ' . $e->getMessage() . "\n";
            } catch (ExchangeError $e) {
                echo '[Exchange Error] ' . $e->getMessage() . "\n";
            } catch (Exception $e) {
                echo '[Error] ' . $e->getMessage() . "\n";
            }
        }
    }

    /**
     * @return array Метод проверки, можно ли делать запрос на список тикеров.
     * Метод для страховки и разработки, в проде скорее всего не потребуется
     */
    private function canCheckBinanceFetchTickersTimeOut(): array
    {
        $cacheKey = 'check_tickers';
        $check = Cache::get($cacheKey);
        if(is_null($check)){
            Cache::put(date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' + 2 minutes')), $cacheKey, 60*self::UPDATE_TIME_MINUTES); // 2 min
            return ['status' => true, 'waitingFor' => '0'];
        }else{
            return ['status' => false, 'waitingFor' => $check];
        }
    }


    /**
     * @return array Массив с вариантами конвертаций с учетом кэша
     * @example
     * [...
     * "1INCH" => ["BTC", "BUSD", "USDT"]
     * "ACH" => ["BTC", "BUSD", "USDT"]
     * ...]
     */
    public function getAllFromToVariants(): array
    {
        $cacheKey = 'tickersToTickers';
        $variants = Cache::get($cacheKey);
        if(is_null($variants)) {
            $tickers = $this->getBinanceTickers();
            $variants = $this->makeAllVariantsArray($tickers['data']);
            Cache::put($variants, $cacheKey, 60*self::UPDATE_TIME_MINUTES); // 2 min
        }
        return $variants;
    }


    /**
     * @param array $tickers Массив с вариантами конвертаций
     * @return array
     * @example
     * [...
     * "1INCH" => ["BTC", "BUSD", "USDT"]
     * "ACH" => ["BTC", "BUSD", "USDT"]
     * ...]
     */
    private function makeAllVariantsArray(array $tickers): array
    {
        ksort($tickers);
        $tickersToTickers = [];
        foreach ($tickers as $tickerToTickerKey => $value){
            $split = explode('/', $tickerToTickerKey);
            if( count($split) == 2){
                $tickersToTickers[ $split[0] ][] = $split[1];

                if(array_key_exists($split[1], $tickersToTickers)){
                    if( !in_array($split[0], $tickersToTickers[ $split[1] ]))
                        $tickersToTickers[ $split[1] ][] = $split[0];
                }else{
                    $tickersToTickers[ $split[1] ] = [ $split[0] ];
                }
            }
        }
        return $tickersToTickers;
    }


    /**
     * @param string $symbol Тикер.
     * @example ETH/BTC
     * @param bool $isReverse Признак обратной конвертации.
     * @example BTC/ETH
     * @return array Массив с данными по конкретному тикеру
     * @throws string Описания ошибок. Обычно траблы с подключением или сетью
     */
    public function getTicker(string $symbol, bool $isReverse = false): array
    {
        if($isReverse)
            $symbol = $this->reverseTickets($symbol);

        $cacheKey = 'fetchTicker_'.str_replace('/', '_', $symbol);
        $tickerData = Cache::get($cacheKey);
        if(is_null($tickerData)) {
            $exchange = new BinanceExchange(array(
                'timeout' => 30000,
            ));
            try {
                $tickerData = $exchange->fetch_ticker ($symbol);

                // Навешиваю кэш, чтобы не забанил Бинанс
                if(!is_null($tickerData))
                    Cache::put($tickerData, $cacheKey, 60*60*24); // 1 day

                return [$symbol => $tickerData];

            } catch (NetworkError $e) {
                echo '[Network Error] ' . $e->getMessage() . "\n";
            } catch (ExchangeError $e) {
                echo '[Exchange Error] ' . $e->getMessage() . "\n";
            } catch (Exception $e) {
                echo '[Error] ' . $e->getMessage() . "\n";
            }
        }
        return $tickerData;

    }

    /**
     * @param string $symbol Символ тикера
     * @return array Стакан с данными
     * @throws string Описания ошибок. Обычно траблы с подключением или сетью
     */
    public function getOrderBook(string $symbol): array
    {
        $cacheKey = 'getOrderBook_'.str_replace('/', '_', $symbol);
        $tickerData = Cache::get($cacheKey);
        if(is_null($tickerData)) {
            $exchange = new BinanceExchange(array(
                'timeout' => 30000,
            ));
            try {
                $tickerData = $exchange->fetch_order_book($symbol, self::ORDER_BOOK_LIMIT);
                // Навешиваю кэш, чтобы дебаг расчетов был верный
                if(!is_null($tickerData))
                    Cache::put($tickerData, $cacheKey, 60*10); // 10 минут

            } catch (NetworkError $e) {
                echo '[Network Error] ' . $e->getMessage() . "\n";
            } catch (ExchangeError $e) {
                echo '[Exchange Error] ' . $e->getMessage() . "\n";
            } catch (Exception $e) {
                echo '[Error] ' . $e->getMessage() . "\n";
            }
        }
        return [$symbol => $tickerData];
    }

    /**
     * @param string $symbol Символ тикера
     * @return array Массив с итоговыми вариантами конвертаций
     */
    public function checkVariants(string $symbol): array
    {
        $data = config('app.variants_array');
        $symbols = $this->symbolToArray($symbol);
        $defaultTickers = $this->buildTickersToTickersArray();
        // Есть в таблице нужный тикер и его объем больше 0 (действующая пара)
        $isReverse = (array_key_exists($this->reverseTickets($symbol), $defaultTickers)
            && $defaultTickers[$this->reverseTickets($symbol)]['bidVolume'] != 0);

        if( (array_key_exists($symbol, $defaultTickers) && $defaultTickers[$symbol]['bidVolume'] != 0)
            || $isReverse){
            $data['level0']['status'] = true;
            $data['level0']['data'] = $this->getTicker($symbol, $isReverse);
        }else{
            // Ex: [BTC, BUSD, USDT, BNB]
            $variantsArray = $this->findVariants($symbol);

            // Ex: ["ETH/BTC", "ETH/BUSD", "ETH/USDT", "BNB/ETH"]
            $accessSymbols1 = $this->findBinanceSymbols($variantsArray, $symbols['from']);

            // Ex: ["XEM/BTC", "XEM/BUSD", "XEM/USDT", "XEM/BNB"]
            $accessSymbols2 = $this->findBinanceSymbols($variantsArray, $symbols['to']);

            $data['level1']['status'] = true;
            $data['level1']['data'] = [
                'fromToGap' => $accessSymbols1,
                'gapToEnd' => $accessSymbols2
            ];

        }
        return $data;
    }

    /**
     * @param string $symbol Символ тикера.
     * @example ETH/BTC
     * @return string Обратный символ тикера.
     * @example BTC/ETH
     */
    private function reverseTickets(string $symbol): string
    {
        $arr = explode('/', $symbol);
        return $arr[1].'/'.$arr[0];
    }

    /**
     * @param array $variants Варианты конвертации по символу
     * @param string $symbol Символ тикера
     * @return array ["ETH/BTC", "ETH/BUSD", "ETH/USDT", "BNB/ETH"]
     */
    private function findBinanceSymbols(array $variants, string $symbol): array
    {
        $array = [];
        $allVariants = $this->getBinanceTickers()['data'];
        foreach ($variants as $coin){
            if(array_key_exists($symbol.'/'.$coin, $allVariants)) {
                $array[] = $symbol . '/' . $coin;
            }else{
                $array[] = $coin . '/' . $symbol;
            }
        }
        return $array;
    }

    /**
     * @param string $symbol Символ тикера
     * @return array Массив вариантов
     */
    private function findVariants(string $symbol): array
    {
        $symbols = $this->symbolToArray($symbol);
        $fromAllToData = $this->getAllFromToVariants();

        // Находим все варианты коинов, которые доступы для обмена из монеты $FROM
        $fromToCoin = array_key_exists($symbols['from'], $fromAllToData) ? $fromAllToData[$symbols['from']] : null;

        // Ex: ["BTC", "BUSD", "USDT", "AAVE", "BKRW", "BNB", "BRL".....]
        $fromToCoin = array_intersect_key($fromAllToData, array_flip($fromToCoin));

        // Ex: ETH -> [BTC, BUSD, USDT, BNB] -> XEM
        return $this->findOneLever($fromToCoin, $symbols['to']);
    }

    /**
     * @param array $fromToCoin Массив с массивами, где ключ коин, значение - массив в коинами, в который можно конвертировать
     * Пример:
     * [...
     * "1INCH" => ["BTC", "BUSD", "USDT"]
     * "ACH" => ["BTC", "BUSD", "USDT"]
     * ...]
     * @param string $to В какой коин нужно конвертировать
     * @return array Массив доступных вариантов. Пример: [BTC, BUSD, USDT, BNB]
     */
    private function findOneLever(array $fromToCoin, string $to): array
    {
        $array = [];
        foreach ($fromToCoin as $coin => $variants){
            if(in_array($to, $variants))
                $array[] = $coin;
        }
        return $array;
    }

    /**
     * @param string $symbol Символ тикера. Прим: ETH/USDT
     * @return array Массив с данными, какой во что конвертируем.
     * Прим: ['from' => 'ETH', 'to' => 'USDT']
     */
    private function symbolToArray(string $symbol): array
    {
        $data = explode('/', $symbol);
        return [
            'from' => $data[0],
            'to' => $data[1]
        ];
    }

    /**
     * @param string $symbol Символ тикера
     * @param string $from В какой коин пытаемся конвертировать
     * @return bool В прямом или обратном порядке идет конвертация
     */
    static public function isInvert(string $symbol, string $from): bool
    {
        return !str_starts_with($symbol, $from);
    }

    /**
     * @param string $symbol Символ тикера
     * @return bool
     * Проверяем, прямая ли у нас пара. Т.е конвертация в фиат.
     * Если да, цены в тикерах указаны ВСЕГДА в фиате
     */
    static function isStraight(string $symbol): bool
    {
        $symbols = explode('/', $symbol);
        return (in_array($symbols[0], self::FIAT_SYMBOLS)
            || in_array($symbols[1], self::FIAT_SYMBOLS));
    }

    /**
     * @param string $symbol Символ тикера
     * @return string Символ, в который конвертируем
     */
    static public function getToSymbol(string $symbol, string $from):string
    {
        if( str_starts_with($symbol, $from) ){
            return str_replace($from.'/', '',$symbol);
        }else{
            return str_replace('/'.$from, '',$symbol);
        }
    }

}