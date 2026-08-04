/**
 * Block content capability contract shared by both admin block editors.
 */
(function (global) {
    'use strict';

    const KEYS = ['skin', 'items', 'count', 'style', 'aos', 'customConfig'];

    function legacy(typeInfo) {
        return {
            skin: !!typeInfo?.skinBasePath,
            items: !!typeInfo?.hasItems,
            count: !!(typeInfo?.hasItems || typeInfo?.hasStyle),
            style: !!typeInfo?.hasStyle,
            aos: true,
            customConfig: !!typeInfo?.adminScript,
        };
    }

    function forType(typeInfo) {
        if (!typeInfo) {
            return KEYS.reduce((result, key) => {
                result[key] = false;
                return result;
            }, {});
        }

        const fallback = legacy(typeInfo);
        const declared = typeInfo?.capabilities;
        if (!declared || typeof declared !== 'object') return fallback;

        return KEYS.reduce((result, key) => {
            result[key] = typeof declared[key] === 'boolean' ? declared[key] : fallback[key];
            return result;
        }, {});
    }

    function outputSettings(typeInfo, modalEditor = false) {
        const capabilities = forType(typeInfo);
        return {
            count: capabilities.count,
            style: capabilities.style && !modalEditor,
            aos: capabilities.aos,
        };
    }

    global.MubloBlockCapabilities = Object.freeze({ forType, outputSettings });
})(window);
