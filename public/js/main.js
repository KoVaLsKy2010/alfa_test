function ajaxCalc(){
    let jsContainer = $('.js-result'),
        spinner = $('.js-spinner');
    $('.js-calc').submit(function(e) {
        let from = $('.js-from').val(),
            to = $('.js-to').val()
        e.preventDefault();
        let form = $(this);

        if(form.hasClass('blocked')){
            return;
        }else{
            form.addClass('blocked');
        }

        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });
        $.ajax({
            beforeSend: function(  ) {
                spinner.addClass('spinner');
            },
            timeout: 0,
            url: form.attr('action'),
            type: form.attr('method'),
            data: form.serialize(),
            success: function (response) { //Данные отправлены успешно
                spinner.removeClass('spinner');
                let html = '';
                if(response.level0.status){
                    let data = response.level0.data, fee = data.marketFEE,  history = data.history;

                    if(response.level0.data.volume == 'fail' && response.level0.data.convertToSum > 0)
                        html += '<b style="color:red">Низкая ликвидность</b> ';

                    html += '<span class="">Вы получите</span><b> ' + response.level0.data.convertToSum + '</b> ' + to;

                    html += '<span class="d-block">Затраты на конвертацию:</span>';

                    for (var symbol in fee) {
                        html += '<span class="d-block">'+symbol+' '+fee[symbol].sum+' '+fee[symbol].symbol+'</span>';
                    }
                    html += '<span class="d-block">Число ордеров: '+data.transactions+'</span>';
                    html += '<span class="d-block" >Конвертации:</span>';
                    for (var step in history) {
                        console.log(step);
                        let symbolStep = history[step];
                        for (var symbol in symbolStep) {
                            let calculationPoint = symbolStep[symbol];
                            let calculation = calculationPoint.calculation;
                            for (var i in calculation) {
                                let transaction = calculation[i];
                                html += '<span class="d-block text-sm">'+symbol+' '+transaction.orderCount+' шт. по цене <b>'+transaction.orderPrice+'</b> за один '+calculationPoint.from+'</span>';
                            }
                        }

                    }

                    jsContainer.html(html);
                }else if(response.level1.status){
                    let data = response.level1.data, bestCoin = '', bestPrice = 0;
                    for (var coin in data) {
                        if(data[coin].convertToSum > bestPrice && data[coin].volume != 'fail'){
                            bestPrice = data[coin].convertToSum;
                            bestCoin = coin;
                        }
                    }
                    for (var coin in data) {
                        if(data[coin].convertToSum){
                            let fee = data[coin].marketFEE, history = data[coin].history;
                            html += '<p>';

                            if(bestCoin == coin)
                                html += '<b style="color:green">Лучшая цена</b> ';

                            if(data[coin].volume == 'fail' && data[coin].convertToSum > 0)
                                html += '<b style="color:red">Низкая ликвидность</b> ';

                            html += from+'->'+coin+'->'+to;
                            html += ' <span class="">Вы получите</span> <b>' + data[coin].convertToSum + '</b> ' + to;

                            html += '<span class="d-block">Затраты на конвертацию:</span>';
                            for (var symbol in fee) {
                                html += '<span class="d-block">'+symbol+' '+fee[symbol].sum+' '+fee[symbol].symbol+'</span>';
                            }
                            html += '<span class="d-block">Число ордеров: '+data[coin].transactions+'</span>';

                            html += '<span class="d-block">Конвертации:</span>'
                            for (var step in history) {
                                let symbolStep = history[step];
                                for (var symbol in symbolStep) {
                                    let calculationPoint = symbolStep[symbol];
                                    let calculation = calculationPoint.calculation;
                                    for (var i in calculation) {
                                        let transaction = calculation[i];
                                        html += '<span class="d-block text-sm">'+symbol+' '+transaction.orderCount+' шт. по цене <b>'+transaction.orderPrice+'</b> за один '+calculationPoint.from+'</span>';
                                    }
                                }

                            }
                            html += '</p>';
                        }
                    }

                    jsContainer.html(html);
                }else{
                    //TODO:
                }
                console.log(response);
                form.removeClass('blocked');
            },
            error: function (e) { // Данные не отправлены
                spinner.removeClass('spinner');
                jsContainer.html('Ошибка. Данные не отправлены.' + e);
                form.removeClass('blocked');
            }
        });
    });
}

function initSelect2(){
    $('.js-from').select2();
    $('.js-to').select2();

    $('.js-from').on('change', function (e) {
        if(this.value == $('.js-to').val())
            $('.js-to').select2('destroy').val("").select2();
    });

    $('.js-to').on('change', function (e) {
        if(this.value == $('.js-from').val())
            $('.js-from').select2('destroy').val("").select2();
    });
}

$(document).ready(function() {
    initSelect2();
    ajaxCalc();
});