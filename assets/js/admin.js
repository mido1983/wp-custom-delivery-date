jQuery(document).ready(function($) {
    // Инициализация датапикеров
    function initDatepicker() {
        $('.webrainbow-datepicker').datepicker({
            dateFormat: 'yy-mm-dd',
            firstDay: 1,
            changeMonth: true,
            changeYear: true
        });
    }
    initDatepicker();

    // Обработка добавления исключенных дат
    $('.add-excluded-date').on('click', function(e) {
        e.preventDefault();
        var template = $('#excluded-date-template').html();
        $('#excluded-dates-list').append(template);
        initDatepicker();
    });

    // Обработка удаления исключенных дат
    $(document).on('click', '.remove-excluded-date', function(e) {
        e.preventDefault();
        $(this).closest('.excluded-date-row').remove();
    });

    // Обработка чекбокса ограничения доставки в метабоксе продукта
    $('#webrainbow_delivery_until_enabled').on('change', function() {
        $('.delivery-until-date-wrapper').toggle(this.checked);
        if (!this.checked) {
            $('#webrainbow_delivery_until_date').val('');
        }
    });

    // Инициализация датапикера для поля ограничения доставки
    $('#webrainbow_delivery_until_date').datepicker({
        dateFormat: 'yy-mm-dd',
        minDate: 0,
        firstDay: 1
    });
});