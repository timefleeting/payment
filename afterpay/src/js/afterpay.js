;(function($,$1){
    var config = "{{CONFIG}}";
    function Payment(){
    }
    Payment.prototype = {
        getConfig: function () {
            return config;
        },
        render: function (params, fn) {
            $1.render(config);
        },
        submit: function (data) {
            $1.submit(data);
        }
    }
   $1.register(config.id, new Payment);

})(NfpaymentUtils, Nfpayment);