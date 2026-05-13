(function () {
    function connect() {
        var es = new EventSource('/__sse');
        es.addEventListener('reload', function () {
            window.location.reload();
        });
        es.onerror = function () {
            es.close();
            setTimeout(connect, 1000);
        };
    }
    connect();
})();
