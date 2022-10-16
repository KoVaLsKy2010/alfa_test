<?php

namespace App\Http\Controllers;

use App\Classes\Binance;
use App\Classes\Calculator;
use Illuminate\Http\Request;


class MainPageController extends Controller
{
    public function index(){

        $binance = new Binance();

        // Формируем Массив для выпадающего списка
        $coins = array_keys($binance->getAllFromToVariants());

        return view('main_page.index', compact('coins') );
    }

    public function calc(Request $request){
        $binance = new Binance();
        $from = $request->post('from') ? $request->post('from') : 'ETH';
        $to = $request->post('to') ? $request->post('to') : 'XEM';
        $symbol = $from.'/'.$to;
        $count = $request->post('count') ? $request->post('count') : 1.4;
        $checkVariants = $binance->checkVariants($symbol);

        if($request->get('symbol'))
            dd($binance->buildTickersToTickersArray()[$request->get('symbol')]);

        if($request->get('order'))
            dd( $binance->getOrderBook($request->get('order')) );

        $calculator = new Calculator();
        $calculator->setExchangeData($from, $to, $count);
        $calculator->setVariants($checkVariants);
        $price = $calculator->getPrice();
        $request->post('count') ? '' : dd($price['level1']['data']);
        return response($price);

    }
}
