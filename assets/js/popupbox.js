(function (window) {
    window.popupContinue = function () {
        window.location.replace(window.location.origin + "/expressly/api/" + XLY.uuid + "/migrate/");
    };
})(window);