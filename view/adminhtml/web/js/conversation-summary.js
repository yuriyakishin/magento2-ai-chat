define([
    'uiElement',
    'uiRegistry'
], function (Element, registry) {
    'use strict';

    return Element.extend({
        defaults: {
            template: 'Yu_AiChat/conversation-summary',
            rawData: {}
        },

        /** @inheritdoc */
        initObservable: function () {
            this._super().observe(['rawData']);

            return this;
        },

        /** @inheritdoc */
        initialize: function () {
            this._super();

            registry.get(this.provider, function (provider) {
                provider.on('data', this.onProviderDataChange.bind(this));
                this.onProviderDataChange(provider.data);
            }.bind(this));

            return this;
        },

        /**
         * @param {Object} data
         */
        onProviderDataChange: function (data) {
            this.rawData(data || {});
        }
    });
});
