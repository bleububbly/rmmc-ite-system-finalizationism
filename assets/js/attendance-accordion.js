/* Visual enhancement only: preserves forms, existing DOM fields, PHP and SQL logic. */
(function(){'use strict';
var page=(location.pathname||'').split('/').pop().toLowerCase();
if(page==='dashboard.php') document.body.classList.add('rmmc-dragon-dashboard');
if(page!=='attendance.php') return;
document.body.classList.add('rmmc-attendance-page');
var panels=document.querySelectorAll('.main > section.panel');
for(var i=0;i<panels.length;i++){
  var panel=panels[i],children=Array.from(panel.children),divider=children.find(function(e){return e.classList.contains('divider')});
  if(!divider) continue;
  var remaining=children.slice(children.indexOf(divider)+1);
  if(!remaining.length||remaining[0].tagName!=='H2'||!remaining[1]||!remaining[1].classList.contains('row'))continue;
  var details=document.createElement('details');details.className='rmmc-event-accordion';
  details.open=new URLSearchParams(location.search).has('event_id');
  var summary=document.createElement('summary'),copy=document.createElement('div');copy.className='rmmc-event-summary-copy';
  var kicker=document.createElement('p');kicker.className='rmmc-event-kicker';kicker.textContent='Event attendance';
  var hint=document.createElement('p');hint.className='rmmc-event-summary-hint';hint.textContent='Select to expand or collapse attendance records';
  var chevron=document.createElement('span');chevron.className='rmmc-event-chevron';chevron.setAttribute('aria-hidden','true');chevron.textContent='⌄';
  copy.append(kicker,remaining[0],remaining[1],hint);summary.append(copy,chevron);
  var body=document.createElement('div');body.className='rmmc-event-body';remaining.slice(2).forEach(function(e){body.append(e)});
  details.append(summary,body);divider.replaceWith(details);
  (function(d){window.addEventListener('beforeprint',function(){d.dataset.printWasOpen=d.open?'1':'0';d.open=true});window.addEventListener('afterprint',function(){if(d.dataset.printWasOpen==='0')d.open=false;delete d.dataset.printWasOpen})})(details);
  break;
}
})();
