;(function($,$1){
    var config = "{{CONFIG}}";
    var i18n = {
        "Klarna_authorized_error": "The purchase was declined by the klarna, please try switch your klarna account  or select another payment method.",
        "Klarna_require_phone": "phone require",
        "Klarna_token_error": "token error",
        "Klarna_authorized_exception": "authorized exception",
    };
    try{
        var configI18n = config['i18n'] || {};
        i18n = $.modeData(i18n, configI18n);
    } catch (e) {}
    function Payment(){
        this.storage = $1.storage();
        this.clientTokenTry = 0;
        this.widgetSelector = ".widget_" + config.id; 
        this.lastClientToken = "";
        this.lastAuthorizedToken = "";
        this.isInit = 0;
        this.canSubmit = 0;
        this.sessionId = '';
        this.sessionExpire = {};
        this.sessionLifetime = 3600 * 2; //second
        this.tokenError = "";
        this.tokenLoading = 0;
    }
    Payment.prototype = {
        getConfig: function () {
            return config;
        },
        //init render
        render: function (params) {
            var self = this;
            var widget = self.widgetSelector;
            self.isInit = 0;
            self.lastClientToken = '';
            self.sessionId = '';
            $.jsLoader(config['initJs'], function () {
                self.clientToken(function (token){
                    if (!token) {
                        console.log(i18n['Klarna_token_error']);
                        return false;
                    }
                    // if (token==self.lastClientToken) {
                    //     $1.render(config);
                    //     return true;
                    // }
                    try {
                        if (document.querySelector(widget)) {
                            Klarna.Payments.init({ client_token: token });
                            self.isInit = 1;
                            //render widget
                            Klarna.Payments.load({
                                container: widget,
                                payment_method_category: 'pay_later'
                            }, { // Data to be updated
                            }, function (res) { // load~callback
                                $1.render(config);
                                self.canSubmit = 1;
                            });
                        } else {
                            $1.render(config);
                        }
                    } catch (e) {
                        console.log(config.id + ' render', e);
                    }
                }, {'render_data': params, 'order': {}});
            })
        },
        submit: function (data) {
            var self = this;
            if (self.lastClientToken=='' && self.tokenLoading==1) {
                setTimeout(function () {
                    self.submit(data);
                }, 300);
                return true;
            }
            if (self.isInit !=1) {
                Klarna.Payments.init({ client_token: self.lastClientToken });
                self.isInit = 1;
            }
            // update session_id
            data['order']['extends'] = {
                "session_id":self.sessionId,
                "client_token": self.lastClientToken
            }
            this.clientToken(function (token){
                if (!token) {
                    $1.submitError(data,{
                        "code": 0,
                        "msg": self.tokenError || i18n['Klarna_token_error'],
                    });
                    return false;
                }
                //Klarna.Payments.init({ client_token: token });
                self.tokenAuthorized(data, function(token){
                    self.lastAuthorizedToken = token;
                    data['order']['device']['authorized_token'] = token;
                    $1.submit(data);
                });
            }, data);
        },
        clientToken: function (fn, data) {
            var self = this, token = "";
            var url = this.storage.render_url || '';
            if (!url) {
                self.lastClientToken='';
                fn(token);
                return false;
            }
            self.tokenLoading = 1;
            if (this.clientTokenTry>2) {
                //error 
                self.clientTokenTry = 0;
                self.lastClientToken='';
                self.tokenLoading = 0;
                fn(token);
                return false;
            }
            
            data = data || {};
            var sessionReset = false;
            //session create or session update or Expired sessions must be recreated
            try{
                if (self.sessionId.length>1
                    && ($.timestamp() - self.sessionExpire[self.sessionId]) > self.sessionLifetime
                ) {
                    data['order']['extends']['session_id'] = "";
                    sessionReset = true;
                }
            } catch (e) {}
            data['id'] = config.id;
            $.ajaxJson(url, data, function (res, state){
                var token = "", sessionId = "", error = "";
                if (state==1) {
                    token = res['client_token'] || '';
                    sessionId = res['session_id'] || '';
                    self.lastClientToken = token;
                    self.sessionId = sessionId;
                    self.sessionExpire[sessionId] = $.timestamp();
                    if (sessionReset) {
                        data['order']['extends']['session_id'] = sessionId;
                    }
                    try{
                        error = res['error'] || '';
                    } catch (e) {}
                }
                if (token!="") {
                    self.clientTokenTry = 0;
                    self.tokenError = "";
                    self.tokenLoading = 0;
                    fn(token, sessionId);
                } else {
                    self.clientTokenTry++;
                    self.tokenError = error;
                    self.clientToken(fn, data);
                }
            });
        }
        ,tokenAuthorized: function (data, fn) {
            var self = this;
            var order = data['order'] || {};
            var shippingData = order['shipping'] || {},
                billingData  = order['billing'] || {};
            var billing = {};
            if ($.isEmptyObject(billingData)) {
                billingData = shippingData;
            }
            if (!$.isEmptyObject(billingData)) {
                billing = {
                    "given_name": billingData['first_name'] || '',
                    "family_name": billingData['last_name'] || '',
                    "email": billingData['email'] || '',
                    "phone": billingData['phone'] || '',
                    "country": billingData['country_code'] || '',
                    "region": billingData['region'] || '',
                    "city": billingData['city'] || '',
                    "postal_code": billingData['postal_code'] || '',
                    "street_address": billingData['street_address'] || '',
                };
                billing['phone'] = $.phoneFormat(billing['phone']);
            }
            var shipping = {
                "given_name": shippingData['first_name'] || '',
                "family_name": shippingData['last_name'] || '',
                "email": shippingData['email'] || '',
                "phone": shippingData['phone'] || '',  //require phone?
                "country": shippingData['country_code'] || '',
                "region": shippingData['region'] || '',
                "city": shippingData['city'] || '',
                "postal_code": shippingData['postal_code'] || '',
                "street_address": shippingData['street_address'] || '',
            };
            shipping['phone'] = $.phoneFormat(billing['phone']);

            var addrData = {
                billing_address: billing,
                shipping_address: shipping,
            };
            try {
                Klarna.Payments.authorize({
                    payment_method_category: 'pay_later'
                }, addrData, function (res) { // authorize~callback
                    var authorization_token = res['authorization_token'] || '';
                    var approved = res['approved'] || false;
                    var showForm = res['show_form'] || false;
                    if (typeof fn === 'function') {
                        if (authorization_token.length>1) {
                            fn(authorization_token);
                            return true;
                        }
                    }
                    var dataRes = Object.deepAssign({},res, data);
                    if (showForm==true&&approved==false) { //cancel
                        $1.submitCancel((dataRes || {}));
                        return true;
                    }
                    //reset weight load
                    var renderData = data['render_data'] || {};
                    if (!$.isEmptyObject(renderData)) {
                        self.render(renderData);
                    }
                    //If the response is show_form: false, the purchase is declined. You should hide the widget, and the user might select another payment method. 
                    //This negative response results from the risk assessment that Klarna executes for the purchase. We do not share information about why a certain purchase was rejected, as we keep our risk and fraud policies internal. 
                    $1.submitError((dataRes || {}),{
                        "code": 0,
                        "msg": i18n['Klarna_authorized_error']
                    });
                });
            }catch(e){
                console.log(config.id + ' authorize', e);
                $1.submitError((data || {}),{
                    "code": 0,
                    "msg": i18n['Klarna_authorized_exception']
                });
            }
        }
    }
   $1.register(config.id, new Payment);

})(NfpaymentUtils, Nfpayment);