;(function($,$1){
    var config = "{{CONFIG}}";

    var i18n = {
        "Awx_jscript_error": "awx jscript error",
    };
    try{
        var configI18n = config['i18n'] || {};
        i18n = $.modeData(i18n, configI18n);
    } catch (e) {}

    function Payment(){
        this.deviceId = '';
    }
    Payment.prototype = {
        getConfig: function () {
            return config;
        },
        render: function (params, fn) {
            var url = config['jscript'] || '';
            if (!url) {
                console.log(i18n['Awx_jscript_error']);
                return false;
            }
            this.deviceId = config['device_id'] || '';
            $.jsLoader(url, function () {
                $1.render(config);
            });
        },
        submit: function (data) {
            data['order']['device']['threeds_id'] = this.deviceId;
            $1.submit(data);
        }
    }
   $1.register(config.id, new Payment);

})(NfpaymentUtils, Nfpayment);