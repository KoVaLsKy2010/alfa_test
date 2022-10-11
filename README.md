# Калькулятор наилучшего курса для покупки/продажи криптовалюты на бирже

За основу для расчета и примера взят Binance
API используется на основе библиотеки [ccxt](https://github.com/ccxt/ccxt)

## Необходимое ПО

* Docker
* Composer v2
* ext-bcmath
* php 8.1
* php8.1-{dom,bcmath,gmp,xml,zip}

## Запуск

    composer install
    ./vendor/bin/sail up