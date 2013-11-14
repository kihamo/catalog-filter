$(document).ready(function() {
    $.ajaxSetup({
        dataType: 'json',
        statusCode: {
            500: function() {
                alert('Возникла внутренняя ошибка');
            },
            404: function() {
                alert('Запрашиваемая страница не найдена');
            }
        },
        beforeSend: function (xhr, settings) {
            settings.url += '?' + $('#filter').serialize();
        }
    });

    $('#filter input[type="submit"]').click(function() {
        $.ajax({
            url: '/search'
        })
        .done(function(response) {
            results = $('#results').empty();

            for(i in response.products) {
                item = response.products[i];
                results.append('<li><h4>' + item.name + '</h4>'
                              + item.description + '</li>');
            }
        });

        return false;
    });

    $('#tooltip a').click(function() {
        $('#filter input[type="submit"]').click();

        $('html, body').animate({
            scrollTop: $('#results').offset().top
        }, 2000);

        return false;
    });

    $('#filter input[type="checkbox"]').change(function() {
        var checkbox = $(this);

        $.ajax({
            url: '/filter'
        })
        .done(function(r) {
            $('#filter input[type="checkbox"]').each(function() {
                that = $(this);

                if(that.val() in r.options)
                {
                    that.next()
                        .css('color', r.options[that.val()] ? 'inherit' : 'red');
                }
            });

            tooltip = $('#tooltip').offset({
                top: checkbox.position().top + 25,
                left: checkbox.position().left + 25
            });

            tooltip.find('span').text(r.total);

            if(r.total)
            {
                tooltip.find('a').show();
            }
            else
            {
                tooltip.find('a').hide();
            }

            tooltip.show();
        });
    });
});