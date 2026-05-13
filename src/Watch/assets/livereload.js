(function () {
    var label = '[djot-watch]';
    function connect() {
        console.log(label, 'opening EventSource /__sse');
        var es = new EventSource('/__sse');
        es.addEventListener('open', function () {
            console.log(label, 'connected, readyState=' + es.readyState);
        });
        // Named server event from router.php.
        es.addEventListener('reload', function (ev) {
            console.log(label, 'reload event received', ev.data);
            window.location.reload();
        });
        // Fallback: also reload on any message event (defensive — if the
        // server ever emits a bare `data:` line without `event: reload`,
        // most browsers route it to 'message').
        es.addEventListener('message', function (ev) {
            console.log(label, 'message event', ev.data);
            if (ev.data && ev.data.indexOf('reload') !== -1) {
                window.location.reload();
            }
        });
        es.onerror = function (ev) {
            console.log(label, 'error, readyState=' + es.readyState + ', reconnecting in 1s');
            es.close();
            setTimeout(connect, 1000);
        };
    }
    connect();
})();
