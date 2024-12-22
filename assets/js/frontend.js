jQuery(document).ready(function($) {
    function initDeliveryDatePicker() {
        if (!$('#delivery_date').length) {
            return;
        }

        // Устанавливаем локализацию для русского языка
        $.datepicker.setDefaults($.datepicker.regional['ru'] = {
            closeText: 'Закрыть',
            prevText: 'Предыдущий',
            nextText: 'Следующий',
            currentText: 'Сегодня',
            monthNames: ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
                'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'],
            monthNamesShort: ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн',
                'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'],
            dayNames: ['Воскресенье', 'Понедельник', 'Вторник', 'Среда',
                'Четверг', 'Пятница', 'Суббота'],
            dayNamesShort: ['Вос', 'Пон', 'Вто', 'Сре', 'Чет', 'Пят', 'Суб'],
            dayNamesMin: ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
            weekHeader: 'Нед',
            dateFormat: 'yy-mm-dd',
            firstDay: 1,
            isRTL: false,
            showMonthAfterYear: false,
            yearSuffix: ''
        });

        // Получаем минимальную дату доставки из PHP
        var minDate = new Date(webRainbowDelivery.min_date);

        // Получаем дату ограничения доставки из настроек
        var deliveryUntilDate = webRainbowDelivery.settings.delivery_until ?
            new Date(webRainbowDelivery.settings.delivery_until) : null;

/*
        function checkDeliveryAvailability(date) {
            console.log(`date: ${date}`);
            console.log('------------')
            // Проверяем ограничение даты доставки
            if (deliveryUntilDate && date > deliveryUntilDate) {
                return [false];
            }

            // Получаем день недели (0 = воскресенье, 1 = понедельник, и т.д.)
            var day = date.getDay();

            // Проверяем разрешенные дни (2 = вторник, 4 = четверг, 5 = пятница)
            if (![2, 3, 5].includes(day)) {
                return [false];
            }

            // Проверяем исключенные даты
            var formattedDate = $.datepicker.formatDate('yy-mm-dd', date);

            // Проверяем на запрет доставки с 27/12 до 01/01 включительно
            var startBlockedDate = new Date(date.getFullYear(), 11, 27); // 27 декабря
            var endBlockedDate = new Date(date.getFullYear() + 1, 0, 2); // 1 января следующего года
            console.log(startBlockedDate)
            console.log(endBlockedDate)
            if (date >= startBlockedDate && date <= endBlockedDate) {
                return [false];
            }

            if (webRainbowDelivery.settings.excluded_dates &&
                webRainbowDelivery.settings.excluded_dates.includes(formattedDate)) {
                return [false];
            }

            return [true];
        }
*/
        function checkDeliveryAvailability(date) {
            console.log(`date: ${date}`);
            console.log('------------');

            // 1. Проверяем ограничение даты доставки (если deliveryUntilDate задана)
            if (deliveryUntilDate && date > deliveryUntilDate) {
                return [false];
            }

            // 2. Блокировка "27 декабря – 2 января" (ДВА диапазона для учёта смены года)
            var y = date.getFullYear();

            // Диапазон 1: 27.12 текущего года – 02.01 следующего
            var startBlockedCurr = new Date(y, 11, 27);   // y-12-27
            var endBlockedCurr   = new Date(y + 1, 0, 2); // (y+1)-01-02

            // Диапазон 2: 27.12 предыдущего года – 02.01 текущего
            var startBlockedPrev = new Date(y - 1, 11, 27); // (y-1)-12-27
            var endBlockedPrev   = new Date(y, 0, 2);       // y-01-02

            // Если дата попала в один из этих интервалов, возвращаем [false]
            if (
                (date >= startBlockedCurr && date <= endBlockedCurr) ||
                (date >= startBlockedPrev && date <= endBlockedPrev)
            ) {
                return [false];
            }

            // 3. Проверка разрешённых дней недели (вторник=2, среда=3, пятница=5)
            var day = date.getDay();
            if (![2, 3, 5].includes(day)) {
                return [false];
            }

            // 4. Проверка на «исключённые» даты (которые явно отключены в настройках)
            var formattedDate = $.datepicker.formatDate('yy-mm-dd', date);
            if (
                webRainbowDelivery.settings.excluded_dates &&
                webRainbowDelivery.settings.excluded_dates.includes(formattedDate)
            ) {
                return [false];
            }

            // 5. Всё в порядке — разрешаем дату
            return [true];
        }


        // Инициализация датапикера
        $('#delivery_date').datepicker({
            minDate: minDate,
            maxDate: deliveryUntilDate || '+3m', // Используем дату ограничения или 3 месяца
            beforeShowDay: checkDeliveryAvailability,
            showOtherMonths: true,
            selectOtherMonths: true,
            changeMonth: true,
            changeYear: true,
            showAnim: 'fadeIn',
            onSelect: function(dateText, inst) {
                //const centralCities = webRainbowDelivery.cities.CentralIsraelLocations;
                const warningMessage = webRainbowDelivery.warning;

                const $citySelect = $('#billing_city');
                const defaultCities = $citySelect.data('default-html') || $citySelect.html();

                // Сохраняем стандартный список городов в data-атрибут, если ещё не сохранён
                if (!$citySelect.data('default-html')) {
                    $citySelect.data('default-html', defaultCities);
                }

                // Получаем день недели из выбранной даты
                const selectedDate = new Date(dateText);
                const dayOfWeek = selectedDate.getDay(); // 3 = Среда

                if (dayOfWeek === 3) { // Если выбрана среда
                  //  alert(warningMessage);

                    // Обновляем список городов
                    const filteredCities = centralCities
                        .map(city => `<option value="${city}">${city}</option>`)
                        .join('');
                    $citySelect.html('<option value="">--Выбрать город доставки--</option>' + filteredCities);
                } else {
                    // Возвращаем стандартный список городов
                    $citySelect.html(defaultCities);
                }
            }

        });

        // Запрещаем ручной ввод даты
        $('#delivery_date').on('keydown paste', function(e) {
            e.preventDefault();
            return false;
        });

        // Добавляем иконку календаря
        if ($('#delivery_date').parent().find('.calendar-icon').length === 0) {
            $('#delivery_date').after('<span class="calendar-icon dashicons dashicons-calendar-alt"></span>');
        }
    }

    // Инициализация при загрузке страницы
    initDeliveryDatePicker();

    // Обработка клика по иконке календаря
    $(document).on('click', '.calendar-icon', function() {
        $(this).prev('#delivery_date').datepicker('show');
    });

    // Стили для иконки календаря
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .calendar-icon {
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
                cursor: pointer;
                color: #666;
            }
            #delivery_date {
                padding-right: 30px;
            }
            .form-row .calendar-icon {
                top: 35px;
            }
            /* Стили для недоступных дат */
            .ui-datepicker-unselectable.ui-state-disabled {
                background: #f5f5f5 !important;
                color: #ccc !important;
            }
            /* Стили для доступных дат */
            .ui-datepicker td:not(.ui-state-disabled) { 
                background: #fff !important;
            }
            .ui-datepicker td:not(.ui-state-disabled) a {
                color: #333 !important;
            }
            /* Стили для выбранной даты */
            .ui-datepicker td.ui-datepicker-current-day a {
                background: #007cba !important;
                color: #fff !important;
            }
        `)
        .appendTo('head');
});
