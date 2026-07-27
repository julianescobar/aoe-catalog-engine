/* SEO debug — pegar en consola del navegador */
(function(){
console.log('Title:',document.querySelector('title')?.textContent);
console.log('Desc:',document.querySelector('meta[name="description"]')?.content);
console.log('Canonical:',document.querySelector('link[rel="canonical"]')?.href);
console.log('Prev:',document.querySelector('link[rel="prev"]')?.href||'(none)');
console.log('Next:',document.querySelector('link[rel="next"]')?.href||'(none)');
console.log('Hreflangs:');
document.querySelectorAll('link[rel="alternate"][hreflang]').forEach(l=>console.log(' '+l.getAttribute('hreflang'),l.getAttribute('href')));
})()
