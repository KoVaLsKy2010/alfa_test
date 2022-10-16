<?php

namespace App\Classes;

class OrderLog
{
    /**
     * @param array ...$args Массив ключ => значение.
     * Если значение типа float, в итоговый массив добавляется форматированная строка
     * @return array
     */
    static public function makeLogArray(array ...$args): array
    {
        $data = [];
        foreach ($args[0] as $argKey => $argValue){
            if(is_float($argValue))
                $data[$argKey.'Formated'] = Calculator::formatPrice($argValue);

            $data[$argKey] = $argValue;
        }
        return $data;
    }
}