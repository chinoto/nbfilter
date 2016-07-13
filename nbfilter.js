function nbfilter_init(data){
	'use strict';
	var
		$=jQuery
		,$filter_block=$('#filter_block')
		,templates=$('#templates')[0]
		,tmpl_group=templates.querySelector('.filter_group')
		,tmpl_cond =templates.querySelector('.filter_cond')
		,v_funcs={ //This is only a pile of functions that are pulled into fields, it could be deleted right after field is assigned with no consequences
			input:function op_input(_elem,value,data){
				var _input=document.createElement('input');
				_input.value=value;
				if(data){
					setTimeout(()=>{
						$(_input)
							.autocomplete({source:function(request,response){
								response($.ui.autocomplete.filter(data,request.term).slice(0,10));
							}})
							.click(function(){$(this).autocomplete('search');});
					},0);
				}
				_elem.appendChild(_input);
			}
			,select:function op_select(_elem,value,data){
				var _select=document.createElement('select');
				data.forEach(v=>{
					if(typeof v==='string'){
						v=[v,v];
					}
					var opt=document.createElement('option');
					opt.value=v[0];
					opt.textContent=v[1];
					if(v[0]==value){opt.selected=true;}
					_select.appendChild(opt);
				});
				_elem.appendChild(_select);
			}
			,read_single_input:function op_rsi($elem){
				return $elem.find(':input').val();
			}
		}
		,fields={ //Order of objects isn't guaranteed... I'll deal with it later >.>
			game:['Game name',{
				contains:['Contains',v_funcs.input,v_funcs.read_single_input]
			}]
			,coop:['Co-op',{
				eq_str:['=',v_funcs.select,v_funcs.read_single_input]
			}]
			,max_players:['Max Players',{
				eq_int:['=',v_funcs.select,v_funcs.read_single_input]
			}]
			,rating:['Rating',{
				eq_str:['=',v_funcs.select,v_funcs.read_single_input]
			}]
			,genre:['Genre',{
				int_in_list:['=',v_funcs.select,v_funcs.read_single_input]
			}]
			,platform:['Platform',{
				eq_int:['=',v_funcs.select,v_funcs.read_single_input]
			}]
			,publisher:['Publisher',{
				contains:['Contains',v_funcs.input,v_funcs.read_single_input]
			}]
			,developer:['Developer',{
				contains:['Contains',v_funcs.input,v_funcs.read_single_input]
			}]
		};
	
	//populate_fields went from 17.25ms to 0.51ms just by creating the options manually!
	(function populate_fields(){
		var
			_field=tmpl_cond.querySelector('.field')
			,i
			,opt;
		for(i in fields){
			opt=document.createElement('option');
			opt.value=i;
			opt.textContent=fields[i][0];
			_field.appendChild(opt);
			//$field.append($('<option/>',{value:i,text:fields[i][0]}));
		}
	}());
	
	function create_group(group){
		group=group||{};
		var
			_group=tmpl_group.cloneNode(true)
			,_data=_group.querySelector('.data');
		if(typeof group.op!=='number'){throw new Error('Expected group.op to be a number');}
		if(group.op===1){_group.querySelector('.connector select').value=1;}
		if(group.data&&group.data.length){
			group.data.forEach(v=>{
				switch(v.type){
					case 'group': _data.appendChild(create_group(v)); break;
					case 'cond' : _data.appendChild(create_cond (v)); break;
					default: throw new Error("Invalid type: "+JSON.stringify(v.type));
				}
			});
		}
		return _group;
	}
	
	function create_cond(cond){
		var _cond=tmpl_cond.cloneNode(true);
		change_field(_cond,cond.field);
		change_op(_cond,cond.op,cond.value);
		return _cond;
	}
	
	function change_field(_cond,change_to,auto_single_op){
		var
			_field=_cond.querySelector('.field')
			,_op=_cond.querySelector('.operator')
			,field
			,ops
			,temp=[]
			,i
			,last_op
			,op_count=0
			,opt;
		
		_op.disabled=true;
		_op.textContent='';
		if(change_to){_field.value=change_to;}
		field=_field.value;
		_cond.querySelector('.value').textContent='';
		if(!fields[field]||!(ops=fields[field][1])){return;}
		
		opt=document.createElement('option');
		opt.textContent='Select an operator'
		opt.value='';
		_op.appendChild(opt);
		
		for(i in ops){
			opt=document.createElement('option');
			opt.textContent=ops[i][0]
			opt.value=last_op=i;
			_op.appendChild(opt);
			++op_count;
		}
		
		_op.disabled=false;
		if(auto_single_op&&(op_count===1)){
			change_op(_cond,last_op);
		}
	}
	
	function change_op(_cond,change_to,value){
		var
			_op=_cond.querySelector('.operator')
			,op
			,field =_cond.querySelector('.field').value
			,_value=_cond.querySelector('.value');
		
		_value.textContent='';
		if(change_to){_op.value=op=change_to;}
		else{op=_op.value;}
		if(
			!fields[field]
			||!fields[field][1][op]
		){return;}
		fields[field][1][op][1](_value,value,data[field]);
	}
	
	function read_group($group){
		if($group.length!==1||!$group.is('.filter_group')){
			throw new Error('Invalid group element');
		}
		return {
			type:"group"
			,op:$group.find('>.connector select').val()|0
			,data:$group.find('>.data>*').map(read_group_data_each).get()
		};
	}
	
	function read_group_data_each(){
		var
			$this=$(this)
			,type=$this.is('.filter_group')*2+$this.is('.filter_cond')*1
			,ret;
		if(type===2){return read_group($this);}
		if(type===1){
			ret={
				type:'cond'
				,field:$this.find('.field').val()
				,op:$this.find('.operator').val()||""
				,value:''
			};
			if(ret.field&&ret.op){
				//I was going to put in checks to make it forgiving, but if you screw up, you should get an error.
				ret.value=fields[ret.field][1][ret.op][2]($this.find('.value'));
			}
			return ret;
		}
		throw new Error("Groups and conditions are mutually exclusive");
	}
	
	$filter_block
		.on('change','.filter_cond .field',function(){change_field($(this).closest('.filter_cond')[0],null,true);})
		.on('change','.filter_cond .operator',function(){change_op($(this).closest('.filter_cond')[0]);})
		.on('click','.delete',function(){$(this).closest('.filter_group,.filter_cond').remove();});
	
	(function filter_block_fill(){
		var _group=create_group(
			{
				"type":"group"
				,"op":1
				,"data":[
					{
						"type":"group"
						,"op":0
						,"data":[
							{"type":"cond","field":"rating","op":"eq_str","value":"M - Mature"}
							,{"type":"cond","field":"game","op":"contains","value":"Me"}
							,{"type":"cond","field":"platform","op":"eq_int","value":"1"}
						]
					}
					,{
						"type":"group"
						,"op":0
						,"data":[
							{"type":"cond","field":"rating","op":"eq_str","value":"T - Teen"}
							,{"type":"cond","field":"genre","op":"int_in_list","value":"5"}
						]
					}
				]
			}
		);
		//Remove delete button and movement handle
		var _tools=_group.querySelector('.connector>*');
		_tools.parentNode.removeChild(_tools);
		
		$filter_block[0].appendChild(_group);
	}());
	
	setTimeout(()=>{
		$filter_block.nestedSortable({
			listType:'ul'
			,handle:'.handle'
			,items:'li'
			//,toleranceElement:'> div'
			//,placeholder:'sortable_placeholder'
			,helper:'clone'
			,opacity:0.5
			,forcePlaceholderSize:true
			,disableNesting:'.filter_cond'
			,tabSize:230
			,protectRoot:true
		});
	},0);
	
	$('#add_group').click(()=>{
		$filter_block.find('>.filter_group>.data').append(
			create_group({type:'group',op:0,data:[{type:'cond'},{type:'cond'}]})
		);
	});
	
	$('#add_cond').click(()=>{
		$filter_block.find('>.filter_group>.data').append(create_cond({}));
	});
	
	$('#submit_filter').click(()=>{
		$.post(
			'?submit'
			,JSON.stringify(read_root_group())
			,(data)=>{
				if (data.error) {alert(data.error);return;}
				var $temp=$('<div/>');
				data.result
					//.sort((a,b)=>a[2].localeCompare(b[2])) //This was just for fun.
					.forEach(v=>{
						var $tr=$('<tr/>').appendTo($temp);
						v.forEach(v2=>{
							$tr.append($('<td/>',{text:v2}));
						});
					});
				$('#result_list').html($temp.children());
			}
		);
	});
	
	window.read_root_group=()=>{
		return read_group($filter_block.children('.filter_group'));
	};
};
