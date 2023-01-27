;(function($,$1){
    var config = "{{CONFIG}}";

    var i18n = {};
    try{
        var configI18n = config['i18n'] || {};
        i18n = $.modeData(i18n, configI18n);
    } catch (e) {}

    function Payment(){
    }
    Payment.prototype = {
        getConfig: function () {
            return config;
        },
        render: function (params) {
            $1.render(config);
        },
        submit: function (data) {
            $1.submit(data);
        },
    }
   $1.register(config.id, new Payment);

})(NfpaymentUtils, Nfpayment);