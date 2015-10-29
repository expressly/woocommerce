(function () {
    popupContinue = function (event) {
        event.style.display = 'none';
        var loader = event.nextElementSibling;
        loader.style.display = 'block';
        loader.nextElementSibling.style.display = 'none';

        window.location.replace(window.location.origin + '?expressly=/expressly/api/' + XLY.uuid + "/migrate");
    };

    popupClose = function (event) {
        window.location.replace(window.location.origin);
    };

    openTerms = function (event) {
        window.open(event.href, '_blank');
    };

    openPrivacy = function (event) {
        window.open(event.href, '_blank');
    };

    (function () {
        // make sure our popup is on top or hierarchy
        content = document.getElementById('xly');
        document.body.insertBefore(content, document.body.children[0]);
    })();
})();