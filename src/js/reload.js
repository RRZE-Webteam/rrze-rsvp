"use strict";

var url = new URL(window.location.href);
var t = url.searchParams.get("reload") * 1000;

setTimeout(function(){window.location.reload(1);}, t);