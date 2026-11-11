(function () {
  'use strict';
  function boot() {
    if (typeof Vue === 'undefined' || typeof comConfig === 'undefined' || typeof Axios === 'undefined') { setTimeout(boot, 100); return; }
    new Vue({ el: '#content', components: { comConfig: comConfig },
      data: function () { return { permissions: [], permissionsReady: false, permissionsLoading: false, tab: 'products', view: 'products', error: '', notice: '', loading: false, busy: false, keywords: '', selected: [], products: [], productTotal: 0, productOptions: [], optionCache: {}, subscriptions: [], subscriptionPage: 1, subscriptionTotal: 0, filterClient: '', filterProduct: '', subscriptionFilter: {keywords:'',status:'',channel:'',mode:'',dates:[]}, subscriptionQuery: {}, recordFilter: {archive:'current',endpoint_id:'',review:'',keywords:'',status:'',channel:'',event_type:'',source:'',target:'',client_id:'',product_id:'',dates:[]}, recordQuery: {archive:'current'}, auditFilter: {keywords:'',action:'',actor:'',actor_id:'',client_id:'',dates:[]}, auditQuery: {}, recordView: 'notifications', pending:[], pendingPage:1, pendingTotal:0, pendingFilter:{keywords:'',status:'',channel:'',event_type:'',source:'',target:'',client_id:'',product_id:'',endpoint_id:'',dates:[]},pendingQuery:{}, audits: [], auditActions: [], auditSettingLabels: {}, auditPage:1, auditTotal:0, auditVisible:false, auditDetail:null, health:null, healthLoading:false, diagnosisVisible:false, diagnosisLoading:false, diagnosis:null, diagnosisError:'', productConfirmVisible:false, productPreview:null,productPreviewEnabled:1, productPreviewLoading:false, productPreviewError:'', records: [], recordPage: 1, recordTotal: 0, settings: {}, channelOverview: [], smsUsage: {}, readinessLoading: false, settingLabels: {}, templates: [], parameters: {}, lastCron: 0, endpoints: [], endpointVisible: false, endpointForm: {}, recordVisible: false, recordDetail: null, reviewVisible: false, reviewForm: {outcome:'unresolved',note:''}, reviewTarget: null, broadcastForm: { product_ids: [], clients: true, groups: false }, preview: null, broadcastKey: '', previewSelection: '',
        productGroups: [], productGroupFilter: 'all', productStateFilter: 'all', productQuery: '', expandedProducts: [], catalogLoaded: false, productRequest: 0,
        // Native tree selection caches disabled descendants; temporary locks use
        // the table loading mask and selectProducts guard instead of eligibility.
        productColumns: [{ colKey: 'row-select', type: 'multiple', width: 44, disabled: ({row}) => !this.allowed('setting') || (row.kind === 'group' && !row.product_count) }, { colKey: 'group', title: '分组名称', width: 200, ellipsis: true }, { colKey: 'name', title: '商品名称', minWidth: 340, ellipsis: true }, { colKey: 'stock', title: '现有库存', width: 104, ellipsis: true }, { colKey: 'enabled', title: '开放订阅', width: 100, align: 'center' }, { colKey: 'demand', title: '补货需求', width: 112 }, { colKey: 'last', title: '最近通知', width: 178 }],
        subscriptionColumns: [{ colKey: 'id', title: '编号', width: 68 }, { colKey: 'client_id', title: '客户', width: 80 }, { colKey: 'product_name', title: '商品名称', minWidth: 340, ellipsis: true }, { colKey: 'threshold', title: '库存门槛', width: 112, ellipsis: true }, { colKey: 'channels', title: '通知渠道', width: 164, ellipsis: true }, { colKey: 'mode', title: '提醒模式', width: 100 }, { colKey: 'status', title: '订阅状态', width: 100, ellipsis: true }, { colKey: 'progress', title: '通知进度', width: 180, ellipsis: true }, { colKey: 'expires', title: '有效期至', width: 178 }, { colKey: 'op', title: '操作', width: 260, fixed: 'right' }],
        recordColumns: [{ colKey: 'id', title: '编号', width: 68 }, { colKey: 'event_type', title: '通知类型', width: 108 }, { colKey: 'products', title: '商品摘要', minWidth: 340, ellipsis: true }, { colKey: 'product_count', title: '商品数', width: 80, align: 'right' }, { colKey: 'target', title: '接收对象', width: 108 }, { colKey: 'channel', title: '通知渠道', width: 100 }, { colKey: 'status', title: '发送状态', width: 116, ellipsis: true }, { colKey: 'created', title: '创建时间', width: 178 }, { colKey: 'receiver', title: '接收地址（已脱敏）', width: 280, ellipsis: true }, { colKey: 'reason', title: '处理说明', width: 300, ellipsis: true }, { colKey: 'op', title: '操作', width: 156, fixed: 'right' }],
        auditColumns: [{colKey:'id',title:'编号',width:68},{colKey:'action',title:'操作类型',width:204,ellipsis:true},{colKey:'actor',title:'操作人',width:124,ellipsis:true},{colKey:'detail',title:'操作内容',minWidth:340,ellipsis:true},{colKey:'created',title:'操作时间',width:178},{colKey:'op',title:'操作',width:96,fixed:'right'}],
        pendingColumns:[{colKey:'id',title:'编号',width:80},{colKey:'product_name',title:'商品名称',minWidth:320,ellipsis:true},{colKey:'target',title:'接收对象',width:120},{colKey:'channel',title:'渠道',width:100},{colKey:'event_type',title:'通知类型',width:108},{colKey:'status',title:'处理阶段',width:120},{colKey:'reason',title:'等待原因',minWidth:280,ellipsis:true},{colKey:'due',title:'计划处理时间',width:178},{colKey:'expires',title:'有效期至',width:178},{colKey:'op',title:'操作',width:120,fixed:'right'}],
        previewColumns: [{colKey:'id',title:'商品编号',width:96},{colKey:'name',title:'商品名称',minWidth:320,ellipsis:true},{colKey:'current',title:'当前状态',width:100},{colKey:'change',title:'本次操作',width:110}],
        diagnosisColumns: [{colKey:'channel',title:'通知渠道',width:100},{colKey:'endpoint_name',title:'地址名称',width:200,ellipsis:true},{colKey:'available',title:'当前可用性',width:240,ellipsis:true},{colKey:'progress',title:'本轮进度',width:116,ellipsis:true},{colKey:'reason',title:'处理说明',minWidth:300,ellipsis:true},{colKey:'due',title:'计划处理时间',width:178}],
        snapshotColumns: [{colKey:'id',title:'商品编号',width:96},{colKey:'name',title:'商品名称',minWidth:340,ellipsis:true},{colKey:'qty',title:'通知时库存',width:116}],
        itemColumns: [{colKey:'product_id',title:'商品编号',width:100},{colKey:'channel',title:'渠道',width:100},{colKey:'source',title:'来源',width:100},{colKey:'status',title:'处理状态',width:116,ellipsis:true},{colKey:'reason',title:'处理说明',minWidth:340,ellipsis:true}],
        attemptColumns: [{colKey:'attempt',title:'次数',width:72},{colKey:'status',title:'发送状态',width:116,ellipsis:true},{colKey:'provider_code',title:'接口返回码',width:124,ellipsis:true},{colKey:'sms_units',title:'短信预计条数',width:124},{colKey:'created',title:'尝试时间',width:178},{colKey:'reason',title:'处理说明',minWidth:300,ellipsis:true}],
        subscriptionStatuses: StockSubscribe.options(['active','complete','cancelled','expired'],'全部订阅状态'), deliveryStatuses: StockSubscribe.options(['pending','queued','sending','accepted','failed','exhausted','unknown','skipped','cancelled','expired'],'全部发送状态'), channelOptions: StockSubscribe.options(['email','sms','webhook'],'全部渠道'), modeOptions: StockSubscribe.options(['once','continuous'],'全部提醒模式'), eventOptions: StockSubscribe.options(['restock','delisted'],'全部通知类型'), sourceOptions: StockSubscribe.options(['automatic','manual'],'全部通知来源'),
        personalClient: '', personalEndpoints: [], personalColumns: [{ colKey: 'id', title: '编号', width: 68 }, { colKey: 'name', title: '名称', width: 220, ellipsis: true }, { colKey: 'kind', title: '类型', width: 112 }, { colKey: 'url_hint', title: '地址（已脱敏）', minWidth: 320, ellipsis: true }, { colKey: 'enabled', title: '状态', width: 80 }, { colKey: 'updated', title: '更新时间', width: 178 }, { colKey: 'op', title: '操作', width: 124, fixed: 'right' }],
        settingsTab: 'general', settingsLoading: false, savedSettings: '', settingsRevision: '', settingsConflict: false, sendQueue: {checked_at:0,channels:[]}, sendQueueLoading: false,
        subscriptionFields: [
          { key: 'max_subscriptions', min: 1, max: 200, unit: '个', help: '每位客户可同时保留 1–200 个有效商品订阅。' },
          { key: 'valid_days', min: 1, max: 730, unit: '天', help: '用于新建或重新订阅，可设置 1–730 天。' }
        ],
        ruleSections: [
          { title: '发送频率', fields: [
            { key: 'email_per_minute', min: 1, max: 1000, unit: '封 / 分钟', help: '全站补货订阅邮件在连续 60 秒内最多提交 1–1000 封，默认 20 封；超出后等待后续分钟调度。' },
            { key: 'sms_per_minute', min: 1, max: 1000, unit: '次 / 分钟', help: '全站补货订阅短信在连续 60 秒内最多提交 1–1000 次，默认 10 次；长短信计费条数仍计入每日额度。' }
          ] },
          { title: '检查与发送', fields: [
            { key: 'check_seconds', min: 60, max: 3600, unit: '秒', help: '60–3600 秒，复用系统每分钟定时任务。' },
            { key: 'merge_seconds', min: 0, max: 300, unit: '秒', help: '0–300 秒；0 表示不等待合并。' },
            { key: 'notification_seconds', min: 60, max: 86400, unit: '秒', help: '60–86400 秒，须大于合并窗口；建议另留 180 秒，排队拥堵时适当加长。' },
            { key: 'cooldown_seconds', min: 60, max: 86400, unit: '秒', help: '60–86400 秒，冷却结束后自动复核库存。' },
            { key: 'webhook_attempts', min: 1, max: 3, unit: '次', help: '1–3 次，包含首次发送；结果未知不自动重发。' }
          ] },
          { title: '短信费用控制', fields: [
            { key: 'sms_signature_length', min: 0, max: 100, unit: '字符', help: '按 UTF-16 字符预留签名与括号长度，默认 10；实际计费以服务商为准。' },
            { key: 'sms_product_seconds', min: 3600, max: 604800, unit: '秒', help: '3600–604800 秒，限制同客户同商品的短信频率。' },
            { key: 'sms_client_daily', min: 0, max: 10000, unit: '条', help: '每人每日 0–10000 条；0 表示禁止发送。' },
            { key: 'sms_site_daily', min: 0, max: 1000000, unit: '条', help: '全站每日 0–1000000 条；0 表示禁止发送。' }
          ] },
          { title:'历史记录',fields:[{key:'history_archive_days',min:0,max:3650,unit:'天',help:'0 表示关闭；启用时至少 30 天。每分钟最多标记 200 条已结束的旧通知，历史详情可筛选查询；保留未知结果、去重与费用事实。'}] }
        ],
        templateColumns: [{ colKey: 'label', title: '通知动作', width: 240, ellipsis: true }, { colKey: 'email', title: '邮件', width: 220, ellipsis: true }, { colKey: 'sms', title: '国内短信', width: 220, ellipsis: true }, { colKey: 'sms_global', title: '国际短信', width: 220, ellipsis: true }],
        parameterColumns: [{ colKey: 'key', title: '参数名', width: 240, ellipsis: true }, { colKey: 'label', title: '用途', minWidth: 360, ellipsis: true }],
        endpointColumns: [{ colKey: 'name', title: '名称', width: 180, ellipsis: true }, { colKey: 'kind', title: '类型', width: 104 }, { colKey: 'latest_result', title: '最近通知状态', width: 132, ellipsis: true }, { colKey: 'latest_at', title: '最近处理时间', width: 178 }, { colKey: 'threshold', title: '库存门槛', width: 104, ellipsis: true }, { colKey: 'product_count', title: '商品数', width: 88, align: 'right' }, { colKey: 'enabled', title: '状态', width: 80 }, { colKey: 'url_hint', title: '地址（已脱敏）', minWidth: 200, ellipsis: true }, { colKey: 'op', title: '操作', width: 248, fixed: 'right' }]

      }; },
      computed: {
        hasPagePermission: function () { return ['view', 'setting', 'broadcast', 'log'].some(this.allowed); },
        settingsDirty: function () { return !!this.savedSettings && JSON.stringify(this.settings) !== this.savedSettings; },
        sendQueueStats: function () {
          var stats={};
          ['email','sms'].forEach(channel=>{
            var row=this.sendQueue.channels.find(x=>x.channel===channel) || {}, key=channel+'_per_minute';
            var rate=Number(this.settings[key]) || Number(row.per_minute) || 1, pending=Number(row.pending_deliveries || 0);
            var minutes=Math.ceil(pending/rate), remaining=Number(row.expires_in_seconds || 0);
            stats[key]={pending:pending,items:Number(row.pending_items || 0),sending:Number(row.sending || 0),minutes:minutes,expires:Number(row.earliest_expires_at || 0),risk:pending>0 && !!row.earliest_expires_at && minutes*60>=remaining,draft:!!row.per_minute && rate!==Number(row.per_minute)};
          });
          return stats;
        },
        productGroupMap: function () { var map = {}; this.productGroups.forEach(g => { map[Number(g.id)] = g; }); return map; },
        productGroupOptions: function () { return [{ label: '全部分类', value: 'all' }].concat(this.productGroups.map(g => { var path = this.groupPath(g.id); return path ? { label: path.map(x => x.name).join(' / '), value: String(g.id) } : null; }).filter(Boolean), [{ label: '未分类或分类异常', value: 'other' }]); },
        filteredProducts: function () {
          var query = this.productQuery.toLocaleLowerCase(), idQuery = /^#?[1-9][0-9]*$/.test(query) ? Number(query.replace(/^#/, '')) : 0;
          return this.products.filter(p => {
            var path = this.groupPath(p.product_group_id);
            if (this.productGroupFilter !== 'all' && (this.productGroupFilter === 'other' ? path && path.length : !path || !path.some(g => String(g.id) === this.productGroupFilter))) return false;
            if (this.productStateFilter !== 'all' && String(p.enabled) !== this.productStateFilter) return false;
            return !query || (idQuery ? Number(p.id) === idQuery : p.name.toLocaleLowerCase().includes(query) || (path || []).some(g => g.name.toLocaleLowerCase().includes(query)));
          });
        },
        productTree: function () {
          var nodes = {}, roots = [], other = { key: 'g-other', kind: 'group', name: '未分类或分类异常', children: [] };
          this.productGroups.forEach(g => { if (this.groupPath(g.id)) nodes[g.id] = Object.assign({}, g, { key: 'g-' + g.id, kind: 'group', children: [] }); });
          this.productGroups.forEach(g => { var node = nodes[g.id]; if (!node) return; if (Number(g.parent_id)) nodes[g.parent_id].children.push(node); else roots.push(node); });
          this.filteredProducts.forEach(p => { (nodes[p.product_group_id] || other).children.push(Object.assign({}, p, { key: 'p-' + p.id, kind: 'product' })); });
          if (other.children.length) roots.push(other);
          var filtering = this.productQuery || this.productGroupFilter !== 'all' || this.productStateFilter !== 'all';
          function summarize(node) {
            if (node.kind === 'product') return node;
            node.children = node.children.map(summarize).filter(Boolean);
            node.product_count = node.children.reduce((n, x) => n + (x.kind === 'product' ? 1 : x.product_count), 0);
            node.enabled_count = node.children.reduce((n, x) => n + (x.kind === 'product' ? Number(!!x.enabled) : x.enabled_count), 0);
            return filtering && !node.product_count ? null : node;
          }
          return roots.map(summarize).filter(Boolean);
        },
        productSelectionKeys: function () {
          var ids = new Set(this.selected), keys = [];
          function walk(row) {
            if (row.kind === 'product') { if (!ids.has(Number(row.id))) return 0; keys.push(row.key); return 1; }
            var count = row.children.reduce((n, child) => n + walk(child), 0);
            if (row.product_count && count === row.product_count) keys.push(row.key);
            return count;
          }
          this.productTree.forEach(walk);
          return keys;
        },
        visibleEnabledCount: function () { return this.filteredProducts.filter(p => Number(p.enabled)).length; },
        visibleSubscriptionColumns: function () {
          var progress = this.subscriptions.some(row => row.notification_wait), cancel = this.allowed('manage') && this.subscriptions.some(row => row.status === 'active');
          var cols = this.subscriptionColumns.filter(c => c.colKey !== 'progress' || progress).map(c => c.colKey === 'channels' ? Object.assign({}, c, {width:StockSubscribe.channelsWidth(this.subscriptions,14,40)}) : c.colKey === 'op' ? Object.assign({}, c, {width:cancel?260:168}) : c);
          if (this.subscriptions.some(row => row.deferred_until)) cols.splice(cols.findIndex(c => c.colKey === 'expires'), 0, {colKey:'deferred',title:'复核不早于',width:178});
          return cols;
        },
        visibleRecordColumns: function () { var review=this.allowed('review') && this.records.some(row=>row.status==='unknown');var retry=this.allowed('manage') && this.records.some(row=>row.status==='failed');var cols=this.recordColumns.map(c=>c.colKey==='op'?Object.assign({},c,{width:review?200:retry?156:96}):c);if(this.records.some(row=>row.review))cols.splice(cols.findIndex(c=>c.colKey==='created'),0,{colKey:'review',title:'人工核对',width:132,ellipsis:true});return cols; },
        recordSnapshotColumns: function () { return this.snapshotColumns.filter(c => c.colKey !== 'qty' || this.recordDetail && this.recordDetail.snapshot.some(p => p.qty !== undefined)); },
        recordAttemptColumns: function () { return this.attemptColumns.filter(c => c.colKey !== 'sms_units' || this.recordDetail && this.recordDetail.channel === 'sms'); },
        parameterRows: function () { return Object.keys(this.parameters).map(key => ({ key: key, label: this.parameters[key] })); }
      },
      watch:{'tab': {handler:'persistView',deep:true}, 'view': {handler:'persistView',deep:true}, 'settingsTab': {handler:'persistView',deep:true}, 'keywords': {handler:'persistView',deep:true}, 'productGroupFilter': {handler:'persistView',deep:true}, 'productStateFilter': {handler:'persistView',deep:true}, 'productQuery': {handler:'persistView',deep:true}, 'expandedProducts': {handler:'persistView',deep:true}, 'filterClient': {handler:'persistView',deep:true}, 'filterProduct': {handler:'persistView',deep:true}, 'subscriptionFilter': {handler:'persistView',deep:true}, 'subscriptionQuery': {handler:'persistView',deep:true}, 'subscriptionPage': {handler:'persistView',deep:true}, 'recordView': {handler:'persistView',deep:true}, 'recordFilter': {handler:'persistView',deep:true}, 'recordQuery': {handler:'persistView',deep:true}, 'recordPage': {handler:'persistView',deep:true}, 'auditFilter': {handler:'persistView',deep:true}, 'auditQuery': {handler:'persistView',deep:true}, 'auditPage': {handler:'persistView',deep:true}, 'pendingFilter': {handler:'persistView',deep:true}, 'pendingQuery': {handler:'persistView',deep:true}, 'pendingPage': {handler:'persistView',deep:true}},
      created:function(){var saved=StockSubscribe.viewState(true);if(saved){Object.keys(saved).forEach(k=>{if(['tab', 'view', 'settingsTab', 'keywords', 'productGroupFilter', 'productStateFilter', 'productQuery', 'expandedProducts', 'filterClient', 'filterProduct', 'subscriptionFilter', 'subscriptionQuery', 'subscriptionPage', 'recordView', 'recordFilter', 'recordQuery', 'recordPage', 'auditFilter', 'auditQuery', 'auditPage', 'pendingFilter', 'pendingQuery', 'pendingPage'].includes(k))this[k]=saved[k];});this.catalogLoaded=Array.isArray(saved.expandedProducts);}},
      mounted: function () { this._leaving=e=>{if(this.settingsDirty){e.preventDefault();e.returnValue='';}};window.addEventListener('beforeunload',this._leaving);this.loadPermissions();this._healthTimer=setInterval(()=>{if(!document.hidden && this.permissionsReady && this.hasPagePermission)this.loadHealth();},60000); },
      beforeDestroy: function () { clearInterval(this._healthTimer);window.removeEventListener('beforeunload',this._leaving); },
      methods: {
        persistView:function(){var state={};['tab', 'view', 'settingsTab', 'keywords', 'productGroupFilter', 'productStateFilter', 'productQuery', 'expandedProducts', 'filterClient', 'filterProduct', 'subscriptionFilter', 'subscriptionQuery', 'subscriptionPage', 'recordView', 'recordFilter', 'recordQuery', 'recordPage', 'auditFilter', 'auditQuery', 'auditPage', 'pendingFilter', 'pendingQuery', 'pendingPage'].forEach(k=>state[k]=this[k]);StockSubscribe.viewState(true,state);},
        event: StockSubscribe.event, status: StockSubscribe.status, tableStatus: StockSubscribe.tableStatus, productSummary: StockSubscribe.productSummary, receiver: StockSubscribe.receiver, progressTip: StockSubscribe.progressTip, channel: StockSubscribe.channel, time: StockSubscribe.time,
        kind: function (v) { return ({ wecom: '企业微信', dingtalk: '钉钉', json: '通用 JSON' })[v] || 'Webhook'; },
        allowed: function (permission) { return this.permissions.includes('auth_stock_subscribe_' + permission); },
        loadPermissions: async function () {
          if (this.permissionsLoading) return;
          this.permissionsLoading = true; this.permissionsReady = false; this.permissions = []; this.error = '';
          try {
            // V10's shared permission helper snapshots localStorage at page load.
            // Query native authorization so an existing login sees newly installed
            // plugin permissions and revoked permissions never trust an old cache.
            var response = await Axios.get('/admin/auth'); var result = response.data;
            if (Number(result.status) !== 200 || !result.data || !Array.isArray(result.data.auth)) throw new Error('读取当前管理员权限失败，请重试。');
            this.permissions = result.data.auth.filter(x => typeof x === 'string');
            try { localStorage.setItem('backAuth', JSON.stringify(this.permissions)); } catch (e) {}
            this.permissionsReady = true;
            var first = ['products', 'broadcast', 'records', 'settings'].find(x => x === 'products' ? this.allowed('view') || this.allowed('setting') : this.allowed(({ records: 'log', broadcast: 'broadcast', settings: 'setting' })[x]));
            var permitted=this.tab==='products'?this.allowed('view')||this.allowed('setting'):this.allowed(({records:'log',broadcast:'broadcast',settings:'setting'})[this.tab]);
            this.tab = permitted ? this.tab : first || 'products';
            if (first) { this.loadHealth();this.tabChanged(); if (this.allowed('setting') && first !== 'settings') this.loadSettings(); }
          } catch (e) { this.error = StockSubscribe.message(e); }
          finally { this.permissionsLoading = false; }
        },
        confirmAction: function (header,body,confirmBtn,cancelBtn) {return new Promise(resolve=>{var dialog=this.$dialog.confirm({header:header,body:body,confirmBtn:confirmBtn,cancelBtn:cancelBtn,onConfirm:()=>{dialog.hide();resolve(true);},onClose:()=>{dialog.hide();resolve(false);}});});},
        readinessLabel: function (state) {return ({closed:'后台关闭',attention:'需要配置',ready:'配置可用'})[state] || '待检查';},
        readinessTip: function (row) {return row.details.map(d=>d.label+'：'+(d.reason || '配置可用')).join('；');},
        refreshReadiness: async function () {if(this.readinessLoading)return;this.readinessLoading=true;try{var d=await this.api('settings');this.channelOverview=d.channel_overview||[];this.smsUsage=d.sms_usage||{};}catch(e){this.error=StockSubscribe.message(e);}finally{this.readinessLoading=false;}},
        refreshSendQueue: async function () {if(this.sendQueueLoading)return;this.sendQueueLoading=true;try{var d=await this.api('settings');this.sendQueue=d.send_queue || {checked_at:0,channels:[]};}catch(e){this.error=StockSubscribe.message(e);}finally{this.sendQueueLoading=false;}},
        showEndpointRecords: function (row) {this.tab='records';this.recordView='notifications';this.recordFilter={archive:'current',endpoint_id:String(row.id),review:'',keywords:'',status:'',channel:'webhook',event_type:'',source:'',target:'group',client_id:'',product_id:'',dates:[]};this.searchRecords();},
        api: function (path, method, body) { return StockSubscribe.api(path, method, body, true); },
        guard: async function (fn) { if (this.busy) return; this.busy = true; this.error = ''; this.notice = ''; try { await fn(); } catch (e) { this.error = StockSubscribe.message(e); this.$message.error(this.error); } finally { this.busy = false; } },
        adminPage: function (p) { return '/' + location.pathname.split('/').filter(Boolean)[0] + '/' + p; },
        clientDetailPage: function (id) { return this.adminPage('client_detail.htm?id=' + Number(id)); },
        productDetailPage: function (id) { return this.adminPage('product_detail.htm?id=' + Number(id)); },
        smsTemplatePage: function (name) { return this.adminPage('notice_sms_template.htm?name=' + encodeURIComponent(name)); },
        tabChanged: function () { if (this.tab === 'products') this.viewChanged(); if (this.tab === 'broadcast') this.searchOptions(''); if (this.tab === 'records') this.loadRecordView(); if (this.tab === 'settings') { this.loadSettings(); this.searchOptions(''); this.loadEndpoints(); } },
        viewChanged: function () { this.view === 'products' ? this.loadProducts() : this.loadSubscriptions(this.subscriptionPage); },
        remember: function (rows) {var cache={};rows.forEach(p=>{cache[Number(p.id)]={label:'#'+p.id+' '+p.name,value:Number(p.id),enabled:!!Number(p.enabled),disabled:!Number(p.enabled)};});var selected=[...this.broadcastForm.product_ids,...(this.endpointForm.product_ids || [])];selected.forEach(id=>{if(!cache[id])cache[id]={label:'#'+id+' 商品已删除或不可用',value:Number(id),disabled:true,enabled:false};});this.optionCache=cache;this.productOptions=Object.values(cache);},
        searchOptions: async function () {var seq=this._optionSeq=(this._optionSeq||0)+1;try{var d=await this.api('products?tree=1');if(seq===this._optionSeq)this.remember(d.list);}catch(e){this.error=StockSubscribe.message(e);}},
        verifyOptions:async function(ids){var d=await this.api('products?tree=1');this.remember(d.list);var invalid=ids.filter(id=>!this.optionCache[id]||!this.optionCache[id].enabled);if(invalid.length)throw new Error('所选商品已删除或关闭补货订阅：'+invalid.map(id=>'#'+id).join('、')+'。请调整选择后重试。');},
        groupPath: function (id) { var path = [], seen = {}; while (Number(id)) { var g = this.productGroupMap[id]; if (!g || seen[id] || path.length > 5) return null; seen[id] = true; path.unshift(g); id = g.parent_id; } return path; },
        productGroupKeys: function () { var keys = []; function walk(rows) { rows.forEach(row => { if (row.kind === 'group' && row.children.length) { keys.push(row.key); walk(row.children); } }); } walk(this.productTree); return keys; },
        expandProducts: function (expand) { this.expandedProducts = expand ? this.productGroupKeys() : []; },
        applyProductFilters: function () { this.productQuery = this.keywords.trim(); this.selected = []; this.expandProducts(true); },
        loadProducts: async function () {
          var request = ++this.productRequest; this.loading = true;
          try {
            var d = await this.api('products?tree=1'); if (request !== this.productRequest) return;
            this.products = d.list; this.productGroups = d.groups; this.productTotal = d.count;
            this.selected = this.selected.filter(id => this.filteredProducts.some(p => Number(p.id) === id));
            if (!this.catalogLoaded) this.expandProducts(true);
            else { var groups = this.productGroupKeys(); this.expandedProducts = this.expandedProducts.filter(key => groups.includes(key)); }
            this.catalogLoaded = true; this.remember(d.list);
          } catch (e) { if (request === this.productRequest) this.error = StockSubscribe.message(e); }
          finally { if (request === this.productRequest) this.loading = false; }
        },
        selectProducts: function (keys) { if (!this.allowed('setting') || this.busy || this.loading) return; var ids = new Set(keys.filter(key => typeof key === 'string' && key.startsWith('p-')).map(key => Number(key.slice(2)))); this.selected = this.filteredProducts.filter(p => ids.has(Number(p.id))).map(p => Number(p.id)); },
        stockLabel: function (row) { return row.inventory_state === 'known' ? row.qty + ' 件' : !Number(row.stock_control) ? '不限量' : row.inventory_state === 'unknown' ? '待确认' : '不可订阅'; },
        demandTip: function (row) { return row.thresholds.map(x => '库存超过 ' + x.threshold + ' 件：' + x.subscribers + ' 人').join('；') || '暂无有效订阅'; },
        showProductSubscriptions: function (row) { this.filterClient = ''; this.filterProduct = String(row.id);this.subscriptionFilter={keywords:'',status:'',channel:'',mode:'',dates:[]};this.subscriptionQuery={product_id:this.filterProduct}; this.view = 'subscriptions'; this.loadSubscriptions(1); },
        configure: function (id, enabled) { this.guard(async () => { await this.api('products', 'POST', { product_ids: [id], enabled: Number(enabled) }); this.notice = '商品订阅设置已保存。'; await this.loadProducts(); }); },
        configureSelected: async function (enabled) {
          if(this.busy || this.productPreviewLoading)return;
          var ids=this.selected.slice();if(!ids.length || ids.length>200){this.$message.error('每次请选择 1–200 个商品。');return;}
          this.productPreviewEnabled=enabled;this.productPreview=null;this.productPreviewError='';this.productConfirmVisible=true;this.productPreviewLoading=true;
          try { this.productPreview=await this.api('products?preview=1&enabled='+enabled+'&product_ids='+ids.join(',')); } catch(e){this.productPreviewError=StockSubscribe.message(e);} finally{this.productPreviewLoading=false;}
        },
        confirmProducts: function () { this.guard(async()=>{if(!this.productPreview || this.productPreviewLoading)throw new Error('请先等待商品预览完成。');var d=this.productPreview;try{await this.api('products','POST',{product_ids:d.product_ids,enabled:d.enabled,preview_fingerprint:d.fingerprint});}catch(e){this.productPreview=null;this.productPreviewError=StockSubscribe.message(e);throw e;}this.productConfirmVisible=false;this.selected=[];this.notice='所选商品设置已保存。';await this.loadProducts();await this.loadHealth();}); },
        searchSubscriptions: function () {this.subscriptionQuery=Object.assign({},this.subscriptionFilter,{client_id:this.filterClient,product_id:this.filterProduct});this.loadSubscriptions(1);},
        resetSubscriptions: function () {this.filterClient='';this.filterProduct='';this.subscriptionFilter={keywords:'',status:'',channel:'',mode:'',dates:[]};this.searchSubscriptions();},
        loadSubscriptions: async function (page) { var seq=this._subscriptionSeq=(this._subscriptionSeq||0)+1;this.loading=true;this.error='';try{var d=await this.api('subscriptions?'+StockSubscribe.query(this.subscriptionQuery,page));if(seq!==this._subscriptionSeq)return;this.subscriptions=d.list;this.subscriptionTotal=d.count;this.subscriptionPage=page||1;}catch(e){if(seq===this._subscriptionSeq)this.error=StockSubscribe.message(e);}finally{if(seq===this._subscriptionSeq)this.loading=false;} },
        showDiagnosis: async function (row) {this.diagnosisVisible=true;this.diagnosis=null;this.diagnosisError='';this.diagnosisLoading=true;try{this.diagnosis=await this.api('subscriptions?diagnose_id='+row.id);}catch(e){this.diagnosisError=StockSubscribe.message(e);}finally{this.diagnosisLoading=false;}},
        loadHealth: async function () {if(this.healthLoading)return;this.healthLoading=true;try{this.health=await this.api((this.allowed('log')?'records':'products')+'?summary=1');}catch(e){this.health={state:'warning',message:'运行状态暂时无法读取，请刷新重试。'};}finally{this.healthLoading=false;}},
        waitLabel: function (seconds) {seconds=Number(seconds||0);return seconds<60?seconds+' 秒':seconds<3600?Math.floor(seconds/60)+' 分钟':Math.floor(seconds/3600)+' 小时 '+Math.floor(seconds%3600/60)+' 分钟';},
        showPendingRecords: function () {this.tab='records';this.recordView='pending';this.pendingFilter={keywords:'',status:'',channel:'',event_type:'',source:'',target:'',client_id:'',product_id:'',endpoint_id:'',dates:[]};this.pendingQuery={};this.loadPending(1);},
        cancel: function (row) { this.guard(async () => { if(!await this.confirmAction('取消客户订阅','取消客户 #'+row.client_id+' 对「'+row.product_name+'」的补货订阅？尚未发送的提醒将停止，历史通知记录会保留。','确认取消订阅','保留订阅'))return;await this.api('subscriptions/' + row.id + '/cancel', 'POST', {}); this.notice = '已取消订阅，尚未发送的任务已停止。'; await this.loadSubscriptions(this.subscriptionPage); }); },
        loadRecordView: function (page) {this.recordView==='audit'?this.loadAudits(page||this.auditPage):this.recordView==='pending'?this.loadPending(page||this.pendingPage):this.loadRecords(page||this.recordPage);},
        searchPending:function(){this.pendingQuery=Object.assign({},this.pendingFilter);this.loadPending(1);},
        resetPending:function(){this.pendingFilter={keywords:'',status:'',channel:'',event_type:'',source:'',target:'',client_id:'',product_id:'',endpoint_id:'',dates:[]};this.searchPending();},
        loadPending:async function(page){var seq=this._pendingSeq=(this._pendingSeq||0)+1;this.loading=true;this.error='';try{var d=await this.api('records?view=pending&'+StockSubscribe.query(this.pendingQuery,page));if(seq!==this._pendingSeq)return;this.pending=d.list;this.pendingTotal=d.count;this.pendingPage=page||1;}catch(e){if(seq===this._pendingSeq)this.error=StockSubscribe.message(e);}finally{if(seq===this._pendingSeq)this.loading=false;}},
        openPending:function(row){if(row.subscription_id && this.allowed('view'))this.showDiagnosis({id:row.subscription_id});else if(row.delivery_id){this.recordView='notifications';this.recordFilter={archive:'all',endpoint_id:'',review:'',keywords:'#'+row.delivery_id,status:'',channel:'',event_type:'',source:'',target:'',client_id:'',product_id:'',dates:[]};this.searchRecords();}},
        searchRecords: function () {this.recordQuery=Object.assign({},this.recordFilter);this.loadRecords(1);},
        resetRecords: function () {this.recordFilter={archive:'current',endpoint_id:'',review:'',keywords:'',status:'',channel:'',event_type:'',source:'',target:'',client_id:'',product_id:'',dates:[]};this.searchRecords();},
        loadRecords: async function (page) {var seq=this._recordSeq=(this._recordSeq||0)+1;this.loading=true;this.error='';try{var d=await this.api('records?'+StockSubscribe.query(Object.assign({archive:'current'},this.recordQuery),page));if(seq!==this._recordSeq)return;this.records=d.list;this.recordTotal=d.count;this.recordPage=page||1;}catch(e){if(seq===this._recordSeq)this.error=StockSubscribe.message(e);}finally{if(seq===this._recordSeq)this.loading=false;}},
        searchAudits: function () {this.auditQuery=Object.assign({},this.auditFilter);this.loadAudits(1);},
        resetAudits: function () {this.auditFilter={keywords:'',action:'',actor:'',actor_id:'',client_id:'',dates:[]};this.searchAudits();},
        loadAudits: async function (page) {var seq=this._auditSeq=(this._auditSeq||0)+1;this.loading=true;this.error='';try{var d=await this.api('records?view=audit&'+StockSubscribe.query(this.auditQuery,page));if(seq!==this._auditSeq)return;this.auditSettingLabels=d.field_labels||{};this.audits=d.list;this.auditTotal=d.count;this.auditPage=page||1;this.auditActions=[{label:'全部操作类型',value:''}].concat(Object.keys(d.actions).map(k=>({value:k,label:d.actions[k]})));}catch(e){if(seq===this._auditSeq)this.error=StockSubscribe.message(e);}finally{if(seq===this._auditSeq)this.loading=false;}},
        auditSummary: function (row) {var d=row.detail;if(d.product_ids)return (d.enabled===undefined?'涉及商品':d.enabled?'开放订阅':'关闭订阅')+' '+d.product_ids.length+' 个';if(d.changes)return '修改 '+d.changes.length+' 项设置';if(d.delivery_id)return '通知 #'+d.delivery_id;if(d.subscription_id)return '订阅 #'+d.subscription_id;if(d.endpoint_id)return 'Webhook #'+d.endpoint_id;if(d.broadcast_id)return '群发 #'+d.broadcast_id;return row.label;},
        retry: function (row) { this.guard(async () => { await this.api('records/' + row.id + '/retry', 'POST', {}); this.notice = '已安排重试，发送前会再次核对库存及接收设置。'; await this.loadRecords(this.recordPage); }); },
        reviewLabel: function (v) {return ({accepted:'已确认受理',not_accepted:'确认未受理',unresolved:'仍无法确认'})[v] || '未登记';},
        openReview: function (row) {this.reviewTarget=row;this.reviewForm={outcome:row.review?row.review.outcome:'unresolved',note:row.review?row.review.note:''};this.reviewVisible=true;},
        saveReview: function () {this.guard(async()=>{await this.api('records/'+this.reviewTarget.id+'/review','POST',this.reviewForm);this.reviewVisible=false;this.notice='核对结果已登记，原始结果保留，未触发重发。';await this.loadRecords(this.recordPage);});},
        nativeLogPage: function (row) {return this.adminPage(row.channel==='sms'?'log_notice_sms.htm':'log_notice_email.htm');},
        loadSettings: async function (force) { if(this.settingsLoading || this.settingsDirty && !force)return;this.settingsLoading = true; try { var d = await this.api('settings'); this.settings = d.settings; this.savedSettings = JSON.stringify(d.settings); this.settingsRevision = d.settings_revision; this.settingsConflict = false; this.settingLabels = d.labels; this.templates = d.templates; this.channelOverview=d.channel_overview||[];this.smsUsage=d.sms_usage||{};this.sendQueue=d.send_queue || {checked_at:0,channels:[]};this.parameters = d.parameters; this.lastCron = d.last_cron_at; } catch (e) { this.error = StockSubscribe.message(e); } finally { this.settingsLoading = false; } },
        reloadSettings: function () { this.guard(async()=>{if(this.settingsDirty){var proceed=await new Promise(resolve=>{var dialog=this.$dialog.confirm({header:'重新读取最新设置',body:'重新读取会放弃当前未保存的草稿，请先记下需要保留的修改。',confirmBtn:'放弃草稿并重新读取',cancelBtn:'保留草稿',onConfirm:()=>{dialog.hide();resolve(true);},onClose:()=>{dialog.hide();resolve(false);}});});if(!proceed)return;}await this.loadSettings(true);}); },
        saveSettings: function () { this.guard(async () => { var data = Object.assign({}, this.settings, {settings_revision:this.settingsRevision}); ['enabled', 'email', 'sms', 'webhook', 'show_quantity', 'show_price'].forEach(k => { data[k] = Number(!!data[k]); }); try{await this.api('settings', 'POST', data);}catch(e){if(String(e.message).includes('其他页面更新'))this.settingsConflict=true;throw e;}this.notice = '插件设置已保存。'; await this.loadSettings(true); }); },
        loadEndpoints: async function () { try { var d = await this.api('endpoints'); this.endpoints = d.list; } catch (e) { this.error = StockSubscribe.message(e); } },
        loadPersonalEndpoints: function () { this.guard(async () => { if (!/^[1-9][0-9]*$/.test(this.personalClient)) throw new Error('请输入客户编号。'); var d = await this.api('personal_endpoints?client_id=' + this.personalClient); this.personalEndpoints = d.list; }); },
        disablePersonal: function (e) { this.guard(async () => { await this.api('personal_endpoints/' + e.id + '/disable', 'POST', {}); var d = await this.api('personal_endpoints?client_id=' + this.personalClient); this.personalEndpoints = d.list; this.notice = '已停用个人地址，尚未发送的任务已停止。'; }); },
        enablePersonal: function (e) { this.guard(async () => { await this.api('personal_endpoints/'+e.id+'/enable','POST',{});var d=await this.api('personal_endpoints?client_id='+this.personalClient);this.personalEndpoints=d.list;this.notice='已恢复启用，旧任务不补发。'; }); },
        openEndpoint: function (e) { this.error = ''; this.notice = ''; this.endpointForm = { id: e ? Number(e.id) : 0, revision:e?Number(e.edit_revision):0, name: e ? e.name : '', kind: e ? e.kind : 'wecom', url: '', secret: '', clear_secret: false, product_ids: e ? e.product_ids.slice() : [], threshold: e ? Number(e.threshold) : 3, enabled: e ? Number(e.enabled) : 1 }; this.endpointVisible = true; this.searchOptions(''); },
        saveEndpoint: function () { this.guard(async () => { await this.api('endpoints', 'POST', this.endpointForm); this.endpointVisible = false; this.notice = '商家群地址已保存，未发送测试消息。'; await this.loadEndpoints(); }); },
        testEndpoint: function (e) { this.guard(async () => { var d = await this.api('endpoints/' + e.id + '/test', 'POST', {}); if (d.status !== 'accepted') throw new Error(d.reason || '测试未确认成功。'); this.notice = '测试消息已被接口受理。'; }); },
        deleteEndpoint: function (e) { this.guard(async () => { var proceed=await new Promise(resolve=>{var dialog=this.$dialog.confirm({header:'删除商家群接收地址',body:'删除「'+e.name+'」？此地址关联 '+e.product_ids.length+' 个商品，尚未发送的群通知将停止。历史通知记录会保留。',confirmBtn:'删除地址',cancelBtn:'保留地址',onConfirm:()=>{dialog.hide();resolve(true);},onClose:()=>{dialog.hide();resolve(false);}});});if(!proceed)return;await this.api('endpoints/' + e.id, 'DELETE'); await this.loadEndpoints(); this.notice = '群接收地址已删除，尚未发送的群通知已停止。'; }); },
        invalidatePreview: function () { this.preview = null; this.broadcastKey = ''; },
        selection: function () { return { product_ids: this.broadcastForm.product_ids.slice(), clients: Number(this.broadcastForm.clients), groups: Number(this.broadcastForm.groups) }; },
        previewBroadcast: function () { this.guard(async () => { var selection = this.selection();await this.verifyOptions(selection.product_ids); this.preview = await this.api('broadcast/preview', 'POST', selection); this.previewSelection = JSON.stringify(selection); this.broadcastKey = StockSubscribe.key(); }); },
        sendBroadcast: function () { this.guard(async () => { if (!this.preview || JSON.stringify(this.selection()) !== this.previewSelection) throw new Error('选择已变化，请重新预览。'); if(Date.now()/1000>this.preview.preview_expires_at){this.invalidatePreview();throw new Error('群发预览已过期，请重新预览。');}var d = await this.api('broadcast', 'POST', Object.assign(this.selection(), { request_key: this.broadcastKey, preview_at:this.preview.preview_at,preview_token:this.preview.preview_token })); this.notice = '已安排 ' + d.items + ' 项补货提醒，将按客户和渠道合并发送。'; this.preview = null; this.broadcastKey = ''; }); }
      }
    });
  }
  boot();
})();
