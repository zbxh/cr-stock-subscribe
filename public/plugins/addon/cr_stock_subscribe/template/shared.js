(function (root) {
  'use strict';
  var statuses = { none: '尚未安排', active: '订阅中', complete: '已完成一次提醒', cancelled: '已取消', expired: '已过期', waiting: '等待条件恢复后复核', deferred: '等待冷却后自动复核', pending: '等待合并／发送', batched: '已合并', queued: '已进入 V10 队列', sending: '正在发送', accepted: '接口已受理', failed: '明确失败，等待重试', exhausted: '尝试次数已用完', unknown: '结果待核对', skipped: '已跳过' };
  function message(e) {
    var data = e && (e.response && e.response.data || e.data), status = Number(e && (e.response && e.response.status || e.status));
    var s = String(data && data.msg || e && e.message || (typeof e === 'string' ? e : ''));
    if (/SQLSTATE|PDOException|<script|stack trace/i.test(s)) return '服务器处理失败，请联系管理员查看日志。';
    if (/timeout|timed out|ECONNABORTED/i.test(s)) return '请求超时，请检查网络后重试。';
    if (/Network ?Error|Failed to fetch|Load failed|ERR_NETWORK/i.test(s)) return '无法连接服务器，请检查网络后重试。';
    if (/[\u3400-\u9fff]/.test(s)) return s;
    if (status === 401) return '登录已过期，请重新登录。';
    if (status === 403) return '没有操作权限，请联系管理员。';
    if (status === 404) return '请求的页面或接口不存在，请刷新页面后重试。';
    if (status === 429) return '请求过于频繁，请稍后重试。';
    if (status >= 500) return '服务器处理失败，请联系管理员查看日志。';
    if (e && e.name === 'SyntaxError' || /Invalid JSON response/i.test(s)) return '服务器响应格式异常，请刷新页面后重试。';
    return '操作暂未完成，请稍后重试。';
  }
  function safePath(value) { return /^\/plugin\/[1-9][0-9]*\/stock_subscribe\.htm(?:\?notification=[a-f0-9]{32})?$/.test(value || '') ? value : ''; }
  function login(path) {
    path = safePath(path || location.pathname + location.search);
    if (path) { try { sessionStorage.setItem('redirectUrl', path); } catch (e) {} }
    location.assign('/login.htm');
  }
  async function api(path, method, body, admin) {
    method = method || 'GET';
    try {
      var result;
      if (admin) {
        var r = await Axios({ url: '/stock_subscribe/' + path, method: method, data: body }); result = r.data;
      } else {
        var headers = {}, token = localStorage.getItem('jwt');
        if (token) headers.Authorization = 'Bearer ' + token;
        if (body) headers['Content-Type'] = 'application/json';
        var response = await fetch('/console/v1/stock_subscribe/' + path, { method: method, credentials: 'same-origin', headers: headers, body: body ? JSON.stringify(body) : undefined });
        try { result = await response.json(); }
        catch (e) { throw new Error(message({status:response.status,message:'Invalid JSON response'})); }
        if (response.status === 401 || result.status === 401) { login(); throw new Error('请先登录。'); }
      }
      if (Number(result.status) !== 200) throw new Error(message({status:Number(result.status),data:result}));
      return result.data;
    } catch (e) { throw new Error(message(e)); }
  }
  function options(values, all) { return [{value:'',label:all}].concat(values.map(function(v){return {value:v,label:statuses[v] || ({email:'邮件',sms:'短信',webhook:'Webhook',once:'提醒一次',continuous:'持续订阅',automatic:'自动通知',restock:'商品补货',delisted:'商品下架',manual:'人工群发'})[v] || v};})); }
  function query(filters, page) { var parts=['page='+Number(page || 1)];Object.keys(filters || {}).forEach(function(key){var value=filters[key];if(key==='dates'){if(value && value[0])parts.push('date_from='+encodeURIComponent(value[0]));if(value && value[1])parts.push('date_to='+encodeURIComponent(value[1]));}else if(value!=='' && value!==null && value!==undefined)parts.push(encodeURIComponent(key)+'='+encodeURIComponent(String(value).trim()));});return parts.join('&'); }
  // Per-login, per-plugin-page state. Never persist destinations, secrets,
  // notification content, drafts, credentials or contact-like search text.
  function viewState(admin, value) {
    try {
      var token=localStorage.getItem(admin?'backJwt':'jwt') || '';if(!token)return null;
      var hash=2166136261;for(var i=0;i<token.length;i++){hash=Math.imul(hash^token.charCodeAt(i),16777619);}
      var key='cr-stock-view:1:'+(admin?'admin:':'client:')+(hash>>>0)+':'+location.pathname;
      if(value===undefined){var saved=JSON.parse(sessionStorage.getItem(key)||'null');return saved&&saved.at>Date.now()-7*86400000?saved.value:null;}
      var clean=function(v,k){if(typeof v==='string'){return v.length<=200&&!(/keywords/i.test(k)&&/@|https?:|(?:\d[\s-]*){11}/i.test(v))?v:'';}if(typeof v==='number')return Number.isFinite(v)?Math.max(0,Math.min(100000,v)):0;if(typeof v==='boolean')return v;if(Array.isArray(v))return v.slice(0,500).map(x=>clean(x,k));if(v&&typeof v==='object'){var o={};Object.keys(v).slice(0,40).forEach(k=>{o[k]=clean(v[k],k);});return o;}return null;};
      sessionStorage.setItem(key,JSON.stringify({at:Date.now(),value:clean(value,'')}));
    } catch(e) {}
    return null;
  }
  root.StockSubscribe = { viewState:viewState, api: api, query: query, options: options, message: message, login: login, safePath: safePath, status: function (s) { return Object.prototype.hasOwnProperty.call(statuses, s) ? statuses[s] : '状态待确认'; },
    time: function (v) { return v ? new Date(Number(v) * 1000).toLocaleString('zh-CN', { hour12: false }) : '—'; },
    // Compact labels are for list cells; full status text stays in details and tooltips.
    tableStatus: function (v) { return ({ none:'尚未安排',active:'订阅中',complete:'已完成',cancelled:'已取消',expired:'已过期',waiting:'条件复核中',deferred:'等待复核',pending:'等待发送',batched:'已合并',queued:'V10 排队中',sending:'正在发送',accepted:'接口已受理',failed:'等待重试',exhausted:'重试已用尽',unknown:'结果待核对',skipped:'已跳过' })[v] || '状态待确认'; },
    channelsWidth: function (rows, size, padding) { var longest = (rows || []).reduce(function (max, row) { var label = (row.channels || []).map(root.StockSubscribe.channel).join('、'); var width = Array.from(label).reduce(function (sum, c) { return sum + (c.charCodeAt(0) > 255 ? size : size / 2 + 1); }, 0); return Math.max(max, width); }, 0); return Math.ceil(Math.max(96, Math.min(184, longest + padding)) / 8) * 8; },
    productSummary: function (row) { var items = row.snapshot || []; return items.length ? items[0].name + (items.length > 1 ? ' 等 ' + items.length + ' 个商品' : '') : '—'; },
    productState: function (row) { return ({listed:'在售',hidden:'已下架',deleted:'已删除',unknown:'待确认'})[row.product_state] || (row.product_delisted ? '已下架' : '在售'); },
    receiver: function (row) { return [row.endpoint_name, row.receiver_hint].filter(Boolean).join(' · ') || '—'; },
    progressTip: function (row) { return (row.notification_wait || '暂无待发提醒') + (row.deferred_until ? '；复核不早于 ' + root.StockSubscribe.time(row.deferred_until) : ''); },
    event: function (v) { return v==='delisted' ? '商品下架' : '商品补货'; },
    channel: function (v) { return ({ email: '邮件', sms: '短信', webhook: 'Webhook' })[v] || '通知'; },
    key: function () { var b = new Uint8Array(16); crypto.getRandomValues(b); return Array.from(b).map(function (x) { return x.toString(16).padStart(2, '0'); }).join(''); }
  };
})(window);
