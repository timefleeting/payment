;(function($,$1){
    var config = "{{CONFIG}}";

    var i18n = {
        'Card_number_mandatory': "A credit or debit card number is mandatory",
        'Card_number_12_20': "Card number should contain between 12 and 20 numeric characters",
        'Card_number_invalid': "The card number entered is invalid",
        'Card_security_code_invalid': "The security code is invalid",
        'Card_expiry_month': "The expiry month is not included",
        'Card_expiry_month_2': "'Expiry Month' must contain exactly 2 numbers",
        'Card_expiry_month_1_12': "'Expiry Month' should be between 01 and 12",
        'Card_expiry_year': "The 'Expiry Year' is not included",
        'Card_expiry_year_4': "'Expiry Year' must contain exactly 4 numbers",
        'Card_expiry_month_year': "'Expiry Month' and 'Expiry Year' together must be a date in the future",
        'Card_card_holder_mandatory': "'Card Holder' name is mandatory",
        'Card_card_holder_30': "'Card Holder' name cannot exceed thirty (30) characters"
    };
    try{
        var configI18n = config['i18n'] || {};
        i18n = $.modeData(i18n, configI18n);
    } catch (e) {}

    var storage = {
        'sdk_url': ['https://payments.worldpay.com/resources/cse/js/worldpay-cse-1.latest.min.js'],
        'error': {
            '101': i18n['Card_number_mandatory'],
            '102': i18n['Card_number_12_20'],
            '103': i18n['Card_number_invalid'],
            '201': i18n['Card_security_code_invalid'],
            '301': i18n['Card_expiry_month'],
            '302': i18n['Card_expiry_month_2'],
            '303': i18n['Card_expiry_month_1_12'],
            '304': i18n['Card_expiry_year'],
            '305': i18n['Card_expiry_year_4'],
            '306': i18n['Card_expiry_month_year'],
            '401': i18n['Card_holder_mandatory'],
            '402': i18n['Card_holder_30']
        }
    }

    function Payment(){
        this.errors = [];
    }
    Payment.prototype = {
        getConfig: function () {
            return config;
        },
        render: function (params) {
            $1.render(config);
        },
        submit: function (data) {
            var self = this;
            var decryptCard = $1.getTempCreditCard() || {};
            $.jsLoop(storage.sdk_url,function(){
                var card = {
                    'cardNumber': decryptCard['card_number'] || '',
                    'expiryMonth': decryptCard['expiry_month'] || '',
                    'expiryYear': decryptCard['expiry_year'] || '',
                    'cvc': decryptCard['cvc'] || '',
                    'cardHolderName': decryptCard['holder_name'] || ''
                };
                var encryptData = self.encryptData(card);
                encryptData = encryptData || '';
                data['order']['device']['encrypt_data'] = encryptData;
                if ((card['cardNumber'].length>1 && encryptData.length<1) || self.errors.length>0) {
                    //error
                    $1.submitError(data, self.errors[0]);
                    return false;
                }
                var threedsUrl = config.type_info.type_token.threeds_url || '';
                //3ds flex
                if ((card['cardNumber'].length>1 || encryptData.length>1) && threedsUrl) {
                    $.formIframe(threedsUrl, {
                        "Bin": decryptCard['card_number'],
                        "JWT": config.type_info.type_token.threeds_params.jwt
                    }, function(res){
                        var SessionId = '';
                        if (res !== undefined && res.Status) {
                            SessionId = res.SessionId || '';
                        }
                        if (SessionId=='') {
                            try{
                                if (res.Payload.ActionCode == "SUCCESS") {
                                    SessionId = res.Payload.SessionId || ''; //V2 collect
                                }
                            } catch (e) {}
                        }
                        data['order']['device']['threeds_id'] = SessionId;
                        $1.submit(data);
                    }, config.type_id);
                    return true;
                }
                //ok
                $1.submit(data);
            });
        },
        encryptData: function (card) {
            var self = this;
            self.errors = [];
            if (card['cardNumber']==''||card['cardNumber']<5) {
                self.errors = [{
                    'code': 102,
                    'msg': storage['error']['102'] || ''
                }];
                return '';
            }
            Worldpay.setPublicKey(config['type_info']['type_token']['key']);
            var errors = [];
            return Worldpay.encrypt(card, function(errorCodes){
                if (errorCodes) {
                    for (var i in errorCodes) {
                        var code  = errorCodes[i];
                            code = String(code);
                        var msg = storage['error'][code] || '';
                        errors.push({
                            'code': code,
                            'msg': msg
                        });
                    }
                    self.errors = errors;
                    return false;
                }
            });
        }
    }
   $1.register(config.id, new Payment);

})(NfpaymentUtils, Nfpayment);