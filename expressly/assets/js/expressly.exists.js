(function() {
    setTimeout(function() {
        var login = confirm('Your email address has already been registered on this store. Please login with your credentials. Pressing OK will redirect you to the login page.');
        if (login) {
            window.location.replace(expressly.home + '/index.php/my-account/');
        }
    }, 500);
})();