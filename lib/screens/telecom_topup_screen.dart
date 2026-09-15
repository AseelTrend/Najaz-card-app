import 'dart:async';
import 'package:flutter/material.dart';
import '../services/telecom_api_service.dart';
import '../theme/app_colors.dart';

class TelecomTopupScreen extends StatefulWidget {
  final int categoryId;
  final String categoryName;
  const TelecomTopupScreen({super.key, required this.categoryId, required this.categoryName});
  @override State<TelecomTopupScreen> createState()=>_TelecomTopupScreenState();
}

class _TelecomTopupScreenState extends State<TelecomTopupScreen>{
  final phone=TextEditingController(), amount=TextEditingController();
  Timer? timer;
  Map<String,dynamic>? network,checkData,selectedBunch;
  List<dynamic> bunches=[];
  String? error,activeTab;
  bool loading=false,checking=false,paying=false;

  @override void dispose(){timer?.cancel();phone.dispose();amount.dispose();super.dispose();}

  void changed(String v){
    timer?.cancel();
    setState((){network=null;bunches=[];checkData=null;selectedBunch=null;activeTab=null;error=null;});
    final p=v.replaceAll(RegExp(r'[^0-9]'),'');
    if(p.length>=8)timer=Timer(const Duration(milliseconds:350),()=>detect(p));
  }

  Future<void> detect(String p)async{
    setState(()=>loading=true);
    try{
      final d=await TelecomApiService.detectNetwork(p);
      if(!mounted)return;
      network=d['network'] as Map<String,dynamic>?;
      final id=int.tryParse('${network?['id']??0}')??0;
      if(id>0)bunches=await TelecomApiService.bunches(id);
      setState((){});
    }catch(e){if(mounted)setState(()=>error=e.toString());}
    finally{if(mounted)setState(()=>loading=false);}
  }

  Future<void> checkNumber()async{
    final p=phone.text.replaceAll(RegExp(r'[^0-9]'),'');
    final n=int.tryParse('${network?['id']??0}')??0;
    if(p.length<7||n<=0){setState(()=>error='أدخل رقم الهاتف أولاً');return;}
    setState((){checking=true;error=null;});
    try{
      final d=await TelecomApiService.checkService(phone:p,networkId:n);
      if(!mounted)return;
      checkData=d['data'] is Map?Map<String,dynamic>.from(d['data']):d;
      setState((){});
    }catch(e){if(mounted)setState(()=>error=e.toString());}
    finally{if(mounted)setState(()=>checking=false);}
  }

  void selectTab(String v){setState((){activeTab=activeTab==v?null:v;error=null;if(activeTab=='amount'||activeTab==null)selectedBunch=null;});}
  String money(double v)=>v==v.roundToDouble()?v.toInt().toString():v.toString();

  List<Map<String,dynamic>> items(String type){
    final out=<Map<String,dynamic>>[];
    for(final x in bunches){
      if(x is! Map)continue;
      final b=Map<String,dynamic>.from(x),s='${b['section']??''}';
      if(type=='fees'&&s=='fees')out.add(b);
      if(type=='bundles'&&s!='fees'&&s!='amount')out.add(b);
    }
    return out;
  }

  String sectionName(String s){
    switch(s){
      case 'fees':return 'الفئات والرسوم';
      case 'bundles':return 'الباقات';
      case 'yemen4g_change':return 'تغيير الباقة';
      case 'yemen4g_credit':return 'رصيد يمن فورجي';
      case 'yemen4g_internet':return 'باقات الإنترنت';
      case 'yemen4g_voice':return 'باقات المكالمات';
      default:return 'الخدمات';
    }
  }

  Future<void> payAmount()async{
    final p=phone.text.replaceAll(RegExp(r'[^0-9]'),'');
    final n=int.tryParse('${network?['id']??0}')??0;
    final a=double.tryParse(amount.text.replaceAll(',','').trim())??0;
    if(p.length<7||n<=0){setState(()=>error='رقم الهاتف غير صحيح');return;}
    if(a<=0){setState(()=>error='أدخل مبلغ الشحن');return;}
    setState((){paying=true;error=null;});
    try{final d=await TelecomApiService.payBalance(phone:p,networkId:n,amountYer:a);if(mounted)await success(d);}
    catch(e){if(mounted)setState(()=>error=e.toString());}
    finally{if(mounted)setState(()=>paying=false);}
  }

  Future<void> payBunch()async{
    final p=phone.text.replaceAll(RegExp(r'[^0-9]'),'');
    final n=int.tryParse('${network?['id']??0}')??0;
    final b=selectedBunch;
    final id='${b?['unified_code']??b?['bunch_id']??b?['id']??''}';
    final a=double.tryParse('${b?['price']??0}')??0;
    if(p.length<7||n<=0||b==null||id.isEmpty||a<=0){setState(()=>error='اختر الفئة أو الباقة أولاً');return;}
    setState((){paying=true;error=null;});
    try{final d=await TelecomApiService.topup(phone:p,networkId:n,bunchId:id,amountYer:a);if(mounted)await success(d);}
    catch(e){if(mounted)setState(()=>error=e.toString());}
    finally{if(mounted)setState(()=>paying=false);}
  }

  Future<void> success(Map<String,dynamic>d)=>showDialog<void>(context:context,builder:(_)=>AlertDialog(title:const Text('تم إرسال العملية'),content:Text('${d['message']??d['msg']??'تم تنفيذ العملية بنجاح'}\nرقم الطلب: ${d['order_id']??d['orderId']??'—'}'),actions:[TextButton(onPressed:()=>Navigator.pop(context),child:const Text('حسناً'))]));

  @override Widget build(BuildContext context){
    final supports=network?['supports_balance']==true||'${network?['supports_balance']}'=='1';
    return Scaffold(backgroundColor:AppColors.bg,appBar:AppBar(title:Text(widget.categoryName.isEmpty?'كبينة السداد':widget.categoryName),backgroundColor:AppColors.bg,elevation:0),body:ListView(padding:const EdgeInsets.fromLTRB(14,8,14,28),children:[
      hero(),const SizedBox(height:14),title('رقم الهاتف'),const SizedBox(height:7),phoneField(),
      if(network!=null)...[const SizedBox(height:8),networkCard(supports),const SizedBox(height:12),checkButton(),const SizedBox(height:14),tabs(),if(activeTab!=null)...[const SizedBox(height:14),content()]],
      if(selectedBunch!=null&&activeTab!='amount')... [const SizedBox(height:12),details()],
      if(error!=null)...[const SizedBox(height:12),errorCard(error!)],
    ]));
  }

  Widget content(){
    switch(activeTab){
      case 'amount':return amountSection();
      case 'fees':return itemsSection('الفئات والرسوم',Icons.category_rounded,items('fees'));
      case 'bundles':return itemsSection('الباقات',Icons.language_rounded,items('bundles'));
      case 'offers':return offers();
      default:return Column(children:[amountSection(),const SizedBox(height:18),itemsSection('الفئات والرسوم',Icons.category_rounded,items('fees')),const SizedBox(height:18),itemsSection('الباقات',Icons.language_rounded,items('bundles'))]);
    }
  }

  Widget amountSection()=>Column(crossAxisAlignment:CrossAxisAlignment.end,children:[header(Icons.account_balance_wallet_rounded,'شحن الرصيد'),const SizedBox(height:9),panel(Column(crossAxisAlignment:CrossAxisAlignment.end,children:[Text('أدخل المبلغ الذي تريد شحنه',style:TextStyle(color:AppColors.text2,fontSize:12)),const SizedBox(height:8),TextField(controller:amount,keyboardType:const TextInputType.numberWithOptions(decimal:true),textAlign:TextAlign.center,style:const TextStyle(fontSize:22,fontWeight:FontWeight.w900),decoration:const InputDecoration(hintText:'0',suffixText:'ر.ي',prefixIcon:Icon(Icons.payments_rounded))),const SizedBox(height:10),SizedBox(width:double.infinity,height:50,child:ElevatedButton.icon(onPressed:paying?null:payAmount,icon:paying?const SizedBox(width:18,height:18,child:CircularProgressIndicator(strokeWidth:2)):const Icon(Icons.send_rounded),label:Text(paying?'جاري التنفيذ...':'شحن الرصيد')))]))]);

  Widget itemsSection(String title,IconData icon,List<Map<String,dynamic>> list){
    if(list.isEmpty)return panel(Text('لا توجد خدمات متاحة حالياً',textAlign:TextAlign.right,style:TextStyle(color:AppColors.text2)));
    final groups=<String,List<Map<String,dynamic>>>{};
    for(final b in list){final s='${b['section']??'bundles'}';groups.putIfAbsent(s,()=>[]).add(b);}
    return Column(crossAxisAlignment:CrossAxisAlignment.end,children:groups.entries.map((e)=>Padding(padding:const EdgeInsets.only(bottom:14),child:Column(crossAxisAlignment:CrossAxisAlignment.end,children:[header(icon,e.key=='fees'?title:sectionName(e.key)),const SizedBox(height:9),GridView.builder(shrinkWrap:true,physics:const NeverScrollableScrollPhysics(),itemCount:e.value.length,gridDelegate:const SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount:2,crossAxisSpacing:9,mainAxisSpacing:9,childAspectRatio:1.13),itemBuilder:(_,i){final b=e.value[i],price=double.tryParse('${b['price']??0}')??0,name='${b['bunch_name']??b['name']??b['code']??''}'.trim(),sel=selectedBunch!=null&&'${selectedBunch!['id']}'=='${b['id']}';return bunchCard(b,name.isEmpty?'الخدمة':name,price,sel);})])))).toList());
  }

  Widget bunchCard(Map<String,dynamic>b,String name,double price,bool selected)=>InkWell(onTap:()=>setState(()=>selectedBunch=b),borderRadius:BorderRadius.circular(16),child:AnimatedContainer(duration:const Duration(milliseconds:160),padding:const EdgeInsets.fromLTRB(11,11,11,9),decoration:BoxDecoration(color:selected?AppColors.primary.withOpacity(.13):AppColors.card,borderRadius:BorderRadius.circular(16),border:Border.all(color:selected?AppColors.primary:AppColors.border,width:selected?1.5:1)),child:Column(crossAxisAlignment:CrossAxisAlignment.end,children:[Row(children:[if(selected)const Icon(Icons.check_circle_rounded,color:AppColors.primary,size:19),const Spacer(),Flexible(child:Text(name,maxLines:2,overflow:TextOverflow.ellipsis,textAlign:TextAlign.right,style:const TextStyle(fontSize:14,fontWeight:FontWeight.w900)))]),const Spacer(),if(price>0)Text('${money(price)} ر.ي',style:TextStyle(color:AppColors.text2,fontSize:11)),const SizedBox(height:8),SizedBox(width:double.infinity,height:36,child:OutlinedButton(onPressed:()=>setState(()=>selectedBunch=b),style:OutlinedButton.styleFrom(side:const BorderSide(color:AppColors.primary),shape:RoundedRectangleBorder(borderRadius:BorderRadius.circular(10)),padding:EdgeInsets.zero),child:Text(selected?'تم الاختيار':'اختيار',style:const TextStyle(fontSize:11,fontWeight:FontWeight.w800))))])));

  Widget offers(){
    if(checkData==null)return panel(Text('اضغط على «فحص الرصيد والسلفة والباقات» لإظهار العروض',textAlign:TextAlign.right,style:TextStyle(color:AppColors.text2)));
    final d=checkData!,o=d['offers'];
    return panel(Column(crossAxisAlignment:CrossAxisAlignment.end,children:[header(Icons.local_offer_rounded,'العروض المتاحة'),const SizedBox(height:10),Row(children:[Expanded(child:stat('الرصيد',d['balance']==null?'—':'${d['balance']} ر.ي')),const SizedBox(width:8),Expanded(child:stat('السلفة',d['loan']==null?'0 ر.ي':'${d['loan']} ر.ي'))]),const SizedBox(height:10),if(o is List&&o.isNotEmpty)...o.map((x)=>Container(width:double.infinity,margin:const EdgeInsets.only(bottom:6),padding:const EdgeInsets.all(10),decoration:BoxDecoration(color:AppColors.card2,borderRadius:BorderRadius.circular(10)),child:Text(x is Map?'${x['offer_name']??x['name']??x['offer_id']??''}':'$x',textAlign:TextAlign.right)) else Text('لا توجد عروض متاحة لهذا الرقم',style:TextStyle(color:AppColors.text2))]));
  }

  Widget details(){final b=selectedBunch!,name='${b['bunch_name']??b['name']??b['code']??'الخدمة'}',price=double.tryParse('${b['price']??0}')??0;return panel(Column(crossAxisAlignment:CrossAxisAlignment.end,children:[header(Icons.receipt_long_rounded,'تفاصيل العملية'),const SizedBox(height:10),row('الخدمة',name),row('رقم الهاتف',phone.text),row('السعر','${money(price)} ر.ي'),row('الخصم من الرصيد','${money(price)} ر.ي',AppColors.green),const SizedBox(height:10),SizedBox(width:double.infinity,height:50,child:ElevatedButton.icon(onPressed:paying?null:payBunch,icon:paying?const SizedBox(width:18,height:18,child:CircularProgressIndicator(strokeWidth:2)):const Icon(Icons.send_rounded),label:Text(paying?'جاري التنفيذ...':'شحن الآن')))]));}

  Widget phoneField()=>TextField(controller:phone,onChanged:changed,keyboardType:TextInputType.phone,textAlign:TextAlign.center,style:const TextStyle(fontSize:20,letterSpacing:2,fontWeight:FontWeight.w700),decoration:InputDecoration(hintText:'7X XXX XXXX',prefixIcon:const Icon(Icons.phone_android_rounded),suffixIcon:loading?const Padding(padding:EdgeInsets.all(14),child:SizedBox(width:16,height:16,child:CircularProgressIndicator(strokeWidth:2))):IconButton(onPressed:(){phone.clear();changed('');},icon:const Icon(Icons.clear_rounded))));
  Widget checkButton()=>SizedBox(width:double.infinity,height:52,child:ElevatedButton.icon(onPressed:checking?null:checkNumber,icon:checking?const SizedBox(width:18,height:18,child:CircularProgressIndicator(strokeWidth:2)):const Icon(Icons.search_rounded),label:Text(checking?'جاري الفحص...':'فحص الرصيد والسلفة والباقات')));
  Widget tabs()=>SizedBox(height:45,child:Row(children:[tabButton('كل الخدمات','all'),const SizedBox(width:5),tabButton('شحن رصيد','amount'),const SizedBox(width:5),tabButton('الفئات والرسوم','fees'),const SizedBox(width:5),tabButton('باقات','bundles'),const SizedBox(width:5),tabButton('عروض','offers')]));
  Widget tabButton(String text,String value){final s=activeTab==value;return Expanded(child:InkWell(onTap:()=>selectTab(value),borderRadius:BorderRadius.circular(22),child:AnimatedContainer(duration:const Duration(milliseconds:150),alignment:Alignment.center,decoration:BoxDecoration(color:s?AppColors.primary:AppColors.card,borderRadius:BorderRadius.circular(22),border:Border.all(color:s?AppColors.primary:AppColors.border)),child:Text(text,textAlign:TextAlign.center,style:TextStyle(fontSize:9,fontWeight:FontWeight.w800,color:s?Colors.white:AppColors.text2)))));}
  Widget networkCard(bool supports){final logo='${network?['logo']??''}'.trim(),name='${network?['name']??network?['name_ar']??''}';return panel(Row(children:[Icon(supports?Icons.check_circle_rounded:Icons.error_outline,color:supports?AppColors.green:AppColors.red),const SizedBox(width:10),Expanded(child:Column(crossAxisAlignment:CrossAxisAlignment.end,children:[Text(name,style:const TextStyle(fontSize:16,fontWeight:FontWeight.w900)),const SizedBox(height:3),Text('تم التعرف على الشبكة تلقائياً',style:TextStyle(color:AppColors.text2,fontSize:11))])),const SizedBox(width:10),networkLogo(logo)]));}
  Widget networkLogo(String logo){if(logo.isEmpty)return Container(width:52,height:52,decoration:BoxDecoration(color:AppColors.card2,borderRadius:BorderRadius.circular(12)),child:const Icon(Icons.signal_cellular_alt_rounded,color:AppColors.primary));return Container(width:52,height:52,padding:const EdgeInsets.all(3),decoration:BoxDecoration(color:Colors.white,borderRadius:BorderRadius.circular(12)),child:Image.network(logo,fit:BoxFit.contain,errorBuilder:(_,__,___)=>const Icon(Icons.sim_card_rounded,color:AppColors.primary)));}
  Widget hero()=>panel(Row(children:[Container(width:44,height:44,decoration:BoxDecoration(gradient:AppColors.balanceGradient,borderRadius:BorderRadius.circular(13)),child:const Icon(Icons.sim_card_rounded,color:Colors.white)),const Spacer(),Column(crossAxisAlignment:CrossAxisAlignment.end,children:[Text(widget.categoryName.isEmpty?'كبينة السداد':widget.categoryName,style:const TextStyle(fontSize:20,fontWeight:FontWeight.w900)),const SizedBox(height:3),Text('شحن رصيد الاتصالات اليمنية',style:TextStyle(color:AppColors.text2,fontSize:12))])]));
  Widget header(IconData i,String s)=>Row(children:[Icon(i,color:AppColors.purple,size:21),const Spacer(),Text(s,style:const TextStyle(fontSize:16,fontWeight:FontWeight.w900))]);
  Widget stat(String t,String v)=>Container(padding:const EdgeInsets.all(11),decoration:BoxDecoration(color:AppColors.card2,borderRadius:BorderRadius.circular(13),border:Border.all(color:AppColors.border)),child:Column(crossAxisAlignment:CrossAxisAlignment.end,children:[Text(t,style:TextStyle(color:AppColors.text2,fontSize:10)),const SizedBox(height:5),Text(v,style:TextStyle(color:AppColors.purple,fontWeight:FontWeight.w900,fontSize:15))]));
  Widget row(String t,String v,[Color? c])=>Padding(padding:const EdgeInsets.symmetric(vertical:7),child:Row(children:[Text(v,style:TextStyle(fontWeight:FontWeight.w800,color:c??AppColors.text)),const Spacer(),Text(t,style:TextStyle(color:AppColors.text2,fontSize:11))]));
  Widget panel(Widget child)=>Container(padding:const EdgeInsets.all(14),decoration:BoxDecoration(color:AppColors.card,borderRadius:BorderRadius.circular(18),border:Border.all(color:AppColors.border)),child:child);
  Widget errorCard(String s)=>Container(padding:const EdgeInsets.all(11),decoration:BoxDecoration(color:AppColors.red.withOpacity(.08),borderRadius:BorderRadius.circular(12),border:Border.all(color:AppColors.red.withOpacity(.22))),child:Text(s,textAlign:TextAlign.right,style:const TextStyle(color:AppColors.red,fontSize:12)));
  Widget title(String s)=>Align(alignment:Alignment.centerRight,child:Text(s,style:const TextStyle(fontSize:14,fontWeight:FontWeight.w800)));
}
