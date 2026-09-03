// Simplified captcha encryption server
// This is a minimal version to test if the server can start

const http = require('http');

const PORT = 8787;

const server = http.createServer((req, res) => {
    res.writeHead(200, { 'Content-Type': 'text/plain' });
    res.end('Captcha Encryption Server is running on port ' + PORT);
});

server.listen(PORT, () => {
    console.log(`Captcha encryption server started on port ${PORT}`);
});
