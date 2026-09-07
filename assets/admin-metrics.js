(function(){
'use strict';
function num(s){var m=String(s||'').replace(/,/g,'').match(/-?\d+(?:\.\d+)?/);return m?parseFloat(m[0]):null;}
function level(label,value){label=(label||'').toLowerCase();var text=(value||'').toLowerCase(),n=num(value);
 if(label.indexOf('critical')>=0&&n!==null)return n>0?'critical':'safe';
 if(label.indexOf('danger')>=0&&n!==null)return n>0?'danger':'safe';
 if(label.indexOf('attention')>=0&&n!==null)return n>0?'attention':'safe';
 if(/fatal|critical|failed|danger/.test(text))return 'critical';
 if(/warning|deprecated|high/.test(text))return 'danger';
 if(/attention|medium|notice/.test(text))return 'attention';
 if(/pass|passed|safe|healthy|enabled|detected|active|stable|improved|low/.test(text)&&!/not active|not detected|disabled/.test(text))return 'safe';
 if(/not active|not detected|disabled|unknown|—|n\/a/.test(text))return 'neutral';
 if(label.indexOf('site health')>=0||label==='score'){if(n===null)return 'neutral';return n>=85?'safe':n>=70?'attention':n>=50?'danger':'critical';}
 if(/risk|runtime score|score/.test(label)){if(n===null)return 'neutral';return n>=80?'critical':n>=60?'danger':n>=35?'attention':'safe';}
 if(/server|loopback|time|latency|attributed|total|sql time|http.*time/.test(label)&&/ms/.test(text)){if(n===null)return 'neutral';return n>=1500?'critical':n>=800?'danger':n>=350?'attention':'safe';}
 if(/slow sql|n\+1|failed actions|overdue|orphan|expired|errors|issues/.test(label)){if(n===null)return 'neutral';return n>=50?'critical':n>=10?'danger':n>0?'attention':'safe';}
 if(/autoload/.test(label)&&/mb/.test(text)){if(n===null)return 'neutral';return n>=5?'critical':n>=2?'danger':n>=1?'attention':'safe';}
 if(/queries/.test(label)){if(n===null)return 'neutral';return n>=250?'critical':n>=150?'danger':n>=80?'attention':'safe';}
 if(/outbound http|http calls/.test(label)){if(n===null)return 'neutral';return n>=10?'danger':n>=4?'attention':'safe';}
 if(/http/.test(label)&&n!==null){return n>=500?'critical':n>=400?'danger':n>=200&&n<400?'safe':'neutral';}
 if(/memory/.test(label)){return 'neutral';}
 if(/variations/.test(label)){if(n===null)return 'neutral';return n>=500?'critical':n>=200?'danger':n>=80?'attention':'safe';}
 return 'neutral';
}
function badge(el,label){if(!el||el.querySelector('.wpfix-metric-badge'))return;var l=level(label,el.textContent);if(l==='neutral'&&String(el.textContent).trim()==='')return;var b=document.createElement('span');b.className='wpfix-metric-badge wpfix-'+l;b.textContent=String(el.textContent).trim();el.textContent='';el.appendChild(b);}
document.addEventListener('DOMContentLoaded',function(){
 var score=document.querySelector('.wp-fixpilot-score strong'); if(score)badge(score,'site health');
 document.querySelectorAll('.wp-fixpilot-card').forEach(function(c){var k=c.querySelector('strong'),v=c.querySelector('span');if(k&&v)badge(v,k.textContent);});
 document.querySelectorAll('.wp-fixpilot-wrap table').forEach(function(t){var heads=[].map.call(t.querySelectorAll('thead th'),function(x){return x.textContent.trim();});t.querySelectorAll('tbody tr').forEach(function(r){var cells=r.children;if(!cells.length)return;if(cells[0].tagName==='TH'&&cells[1])badge(cells[1],cells[0].textContent);else if(heads.length){for(var i=0;i<cells.length&&i<heads.length;i++){if(/risk|score|status|severity|impact|http|time|server|sql|queries|count|total|memory|transients|cron|actions|variations|runtime|delta|free|size/i.test(heads[i]))badge(cells[i],heads[i]);}}});});
 document.querySelectorAll('.wp-fixpilot-wrap p strong').forEach(function(s){if(/critical|high|medium|low|safe|pass|failed|warning|attention/i.test(s.textContent))badge(s,'status');});
});
})();
