"use strict";
var cloudscraper;
try {
	cloudscraper = require('cloudflare-scraper');
} catch(x) {
	// currently using https://github.com/EIGHTFINITE/cloudscraper
	cloudscraper = require('cloudscraper');
}

var server = require('http').createServer(function(req, resp) {
	// hack to support HTTPS
	var url = req.url;
	if(req.headers['X-CF-Use-HTTPS']) {
		url = url.replace(/^http:\/\//, 'https://');
		delete req.headers['X-CF-Use-HTTPS'];
	}
	// these cause CloudScraper to go crazy for whatever reason
	delete req.headers.host;
	delete req.headers.Host;
	
	cloudscraper[req.method.toLowerCase()]({
		method: req.method,
		headers: req.headers,
		uri: url,
		resolveWithFullResponse: true,
		simple: false,
		encoding: null
	}).then(function(response, body) {
		resp.writeHead(response.statusCode, response.headers);
		resp.write(response.body);
		resp.end();
	}).catch(err => {
		if(err.error == 'captcha')
			console.log(url + ': captcha error');
		else
			console.log(url + ': ' + (err ? 'error: ' + JSON.stringify(err):'success'));
		resp.writeHead(502);
		resp.write(JSON.stringify(err.error));
		resp.end();
	});
});
server.on('clientError', function(err) {
	console.log('ERROR: ', err);
});
server.listen(8880, '127.0.0.1');
