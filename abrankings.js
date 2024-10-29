$(window).load(function() {
    jQuery.getJSON('http://abranker.test/test?abr_id=1&url=http://www.seychelles.org/',{}, function(data) {
        console.log(data);
    });
})