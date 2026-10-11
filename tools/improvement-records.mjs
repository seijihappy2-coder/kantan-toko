export const STATUSES = ['計画中','実施済み','効果検証中','効果確認済み','保留'];
export const VISIBILITIES = ['private','internal','public'];
export const SECTIONS = {problem:'課題',reason:'判断の理由',changes:'実施内容',before:'改善前',after:'改善後',result:'確認結果',metrics:'比較する数値',next:'次の作業',sources:'出典・根拠',images:'画像・添付資料'};
const KEYS = ['record_schema','id','tool','title','date','status','visibility','target_url','commit'];
const DATE = /^\d{4}-\d{2}-\d{2}$/;
export function validDate(value){if(!DATE.test(value))return false;const d=new Date(value+'T00:00:00Z');return !Number.isNaN(d.getTime())&&d.toISOString().slice(0,10)===value;}
export function safeUrl(value){if(!value)return '';try{const u=new URL(value);return ['http:','https:'].includes(u.protocol)&&!u.username&&!u.password?u.href:'';}catch{return '';}}
export function validateRecord(r){
 if(!r||r.record_schema!==1)throw Error('対応していない記録形式です');
 if(!/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}$/.test(r.id||''))throw Error('記録IDが不正です');
 if(!validDate(r.date))throw Error('実在する日付を指定してください');
 for(const k of ['tool','title'])if(typeof r[k]!=='string'||!r[k].trim()||r[k].length>300)throw Error('ツール名とタイトルを入力してください');
 if(!STATUSES.includes(r.status)||!VISIBILITIES.includes(r.visibility))throw Error('状態または公開範囲が不正です');
 if(typeof r.target_url!=='string'||(r.target_url&&!safeUrl(r.target_url)))throw Error('対象URLは認証情報を含まないhttp/httpsにしてください');
 if(typeof r.commit!=='string'||(r.commit&&!/^[a-f0-9]{7,40}$/i.test(r.commit)))throw Error('コミットは7〜40桁のSHAにしてください');
 for(const k of Object.keys(SECTIONS))if(typeof r[k]!=='string'||r[k].length>50000)throw Error('本文が不正または長すぎます');
 for(const v of Object.values(r))if(typeof v==='string'&&v.includes('\u0000'))throw Error('制御文字を含む記録は読み込めません');
 if(r.status==='効果確認済み'&&(!r.result.trim()||!r.sources.trim()))throw Error('効果確認済みには確認結果と根拠が必要です');
 return r;
}
export function blankRecord(tool=''){return {record_schema:1,id:'record-'+crypto.randomUUID(),tool,title:'',date:new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Tokyo'}).format(new Date()),status:'計画中',visibility:'private',target_url:'',commit:'',...Object.fromEntries(Object.keys(SECTIONS).map(k=>[k,'']))};}
export function toMarkdown(record){const r=validateRecord(record);return '---\n'+KEYS.map(k=>k+': '+JSON.stringify(r[k])).join('\n')+'\n---\n\n# '+r.title.replace(/[\r\n]/g,' ')+'\n\n'+Object.entries(SECTIONS).map(([k,label])=>'## '+label+'\n\n'+(r[k]||'')+'\n').join('\n');}
export function fromMarkdown(text){
 if(typeof text!=='string'||text.length>600000)throw Error('MDファイルが大きすぎます');
 text=text.replace(/^\uFEFF/,'').replace(/\r\n?/g,'\n');
 const header=text.match(/^---\n([\s\S]*?)\n---\n/);if(!header)throw Error('改善記録用のfrontmatterがありません');
 const r={};for(const line of header[1].split('\n')){if(!line.trim())continue;const m=line.match(/^([a-z_]+):\s*(.*)$/);if(!m||!KEYS.includes(m[1])||Object.hasOwn(r,m[1]))throw Error('frontmatterの項目が不正または重複しています');try{r[m[1]]=JSON.parse(m[2]);}catch{if(m[1]==='record_schema')throw Error('record_schemaは数値にしてください');if(!m[2].trim()||/[\[\]{}]/.test(m[2]))throw Error('frontmatterの値を二重引用符で囲んでください');r[m[1]]=m[2].trim();}}
 const body=text.slice(header[0].length);const headings=[...body.matchAll(/^## (.+)\n/gm)];const seen=new Set();for(let i=0;i<headings.length;i++){const label=headings[i][1];const key=Object.keys(SECTIONS).find(k=>SECTIONS[k]===label);if(!key)throw Error('未知の見出しです。小見出しは###を使ってください');if(seen.has(key))throw Error('見出しが重複しています');seen.add(key);r[key]=body.slice(headings[i].index+headings[i][0].length,i+1<headings.length?headings[i+1].index:body.length).replace(/^\n/,'').replace(/\n+$/,'');}
 for(const k of Object.keys(SECTIONS))r[k]??='';return validateRecord(r);
}
export function filename(r){validateRecord(r);return r.date+'_'+r.id+'.md';}
export function fromCompassLog(log,project){
 if(!log||!project||log.projectId!==project.id)throw Error('履歴と挑戦の対応が不正です');
 const r=blankRecord('ai-monetize-compass');r.id='compass-'+String(log.id).replace(/[^a-zA-Z0-9_-]/g,'-').slice(0,80);r.title=log.title;r.date=log.date;r.status='実施済み';r.changes=log.note||'';r.result='既存の作業履歴から作成した下書きです。作業の完了と効果の確認は別に確認してください。';r.sources='AIマネタイズ・コンパスの作業履歴／'+project.name+'／種類：'+log.kind;r.next=project.next||'';r.metrics='記録済みの売上：'+log.revenue+'円\n記録済みの費用：'+log.cost+'円\n記録済みの作業時間：'+log.minutes+'分\n期間の比較・効果判定は未記入。';return validateRecord(r);
}
