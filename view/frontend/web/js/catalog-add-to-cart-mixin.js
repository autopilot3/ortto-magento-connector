define([
    'jquery',
    'add_to_cart'
], function ($, addToCart) {
    'use strict';

    return function (widget) {
        $.widget('mage.catalogAddToCart', widget, {
            interceptors: {
                afterAjaxSubmit: false
            },
            _create: function () {
                this._super();
                if (!this.interceptors.afterAjaxSubmit) {
                    // Raised in Magento_Catalog::js/catalog-add-to-cart.js ajax::success handler
                    $(document).bind('ajax:addToCart', function (_, data) {
                        if (!data || !data.response || data.response.backUrl) {
                            return;
                        }
                        addToCart({
<<<<<<< HEAD
                            sku: data.sku,
=======
                            product_ids: data.productIds,
                            sku: data.sku
>>>>>>> parent of 9cfd2de (Update product-added-to-cart handler)
                        });
                    });
                    this.interceptors.afterAjaxSubmit = true;
                }
            },
        });

        return $.mage.catalogAddToCart;
    }
});
