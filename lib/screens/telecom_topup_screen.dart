import 'dart:async';
import 'package:flutter/material.dart';
import '../services/telecom_api_service.dart';
import '../theme/app_colors.dart';

class TelecomTopupScreen extends StatefulWidget {
  final int categoryId;
  final String categoryName;
  const TelecomTopupScreen({super.key, required this.categoryId, required this.categoryName});
  @override State<TelecomTopupScreen> createState() => _TelecomTopupScreenState();
}

class _TelecomTopupScreenState extends State<TelecomTopupScreen> {
  final phone=TextEditingController(), amount=TextEditingController();
  Timer? timer; Map<String,dynamic>? network; List<dynamic> quick=[];
  bool loading=false,paying=false; String? error;
  @override void dispose(){timer?.cancel();phone.dispose();amount.dispose();super.dispose();}
  void changed(String v){timer?.cancel();setState((){network=null;quick=[];error=null;});final p=v.replaceAll(RegExp(r'[^0-9]'),'');if(p.length>=8)timer=Timer(const Duration(milliseconds:350),()=>detect(p));}
  Future<void> detect(String p)async{setState(()=>loading=true);try{final d=await TelecomApiService.detectNetwork(p);if(!mounted)return;network=d['network'] as Map<String,dynamic>?;final id=int.tryParse('${network?['id']??0}')??0;if(id>0)quick=await TelecomApiService.quickAmounts(id);if(mounted)setState((){});}catch(e){if(mounted)setState(()=>error=e.toString());}finally{if(mounted)setState(()=>loading=false);}}
  Future<void> submit()async{final p=phone.text.replaceAll(RegExp(r'[^0-9]'),'');final n=int.tryParse('${network?['id']??0}')??0;final a=double.tryParse(amount.text)??0;if(p.length<8||n<=0||a<=0){setState(()=>error='تحقق من الرقم والشبكة والمبلغ');return;}setState((){paying=true;error=null;});try{final d=await TelecomApiService.payBalance(phone:p,networkId:n,amountYer:a);if(!mounted)return;showDialog(context:context,builder:(_)=>AlertDialog(title:const Text('تم إرسال العملية'),content:Text('${d['message']??'تم الشحن بنجاح'}\nرقم الطلب: ${d['order_id']??'—'}'),actions:[TextButton(onPressed:()=>Navigator.pop(context),child:const Text('حسناً'))]));}catch(e){if(mounted)setState(()=>error=e.toString());}finally{if(mounted)setState(()=>paying=false);}}
  @override Widget build(BuildContext c){final supports=network?['supports_balance']==true||'${network?['supports_balance']}'=='1';return Scaffold(backgroundColor:AppColors.background,appBar:AppBar(title:Text(widget.categoryName.isEmpty?'كبينة السداد':widget.categoryName),backgroundColor:AppColors.background,elevation:0),body:ListView(padding:const EdgeInsets.all(14),children:[
    Container(padding:const EdgeInsets.all(18),decoration:BoxDecoration(color:AppColors.card,borderRadius:BorderRadius.circular(18),border:Border.all(color:AppColors.border)),child:const Column(crossAxisAlignment:CrossAxisAlignment.end,children:[Icon(Icons.sim_card_rounded,size:34,color:AppColors.primary),SizedBox(height:8),Text('كبينة السداد',style:TextStyle(fontSize:20,fontWeight:FontWeight.w800)),SizedBox(height:4),Text('شحن رصيد الاتصالات اليمنية',style:TextStyle(color:AppColors.text2,fontSize:12))])),
    const SizedBox(height:18),_title('رقم الهاتف'),const SizedBox(height:7),TextField(controller:phone,onChanged:changed,keyboardType:TextInputType.phone,textAlign:TextAlign.center,style:const TextStyle(fontSize:20,letterSpacing:2,fontWeight:FontWeight.w700),decoration:InputDecoration(hintText:'7X XXX XXXX',prefixIcon:Icon(Icons.phone_android_rounded),suffixIcon:loading?const Padding(padding:EdgeInsets.all(14),child:SizedBox(width:16,height:16,child:CircularProgressIndicator(strokeWidth:2))):IconButton(onPressed:()=>phone.clear(),icon:const Icon(Icons.clear_rounded)))),
    if(network!=null)Container(margin:const EdgeInsets.only(top:9),padding:const EdgeInsets.all(12),decoration:BoxDecoration(color:AppColors.primary.withOpacity(.12),borderRadius:BorderRadius.circular(12),border:Border.all(color:AppColors.primary.withOpacity(.35))),child:Row(children:[const Icon(Icons.signal_cellular_alt_rounded,color:AppColors.primary),const SizedBox(width:10),Expanded(child:Text('${network!['name']??''}',style:const TextStyle(fontWeight:FontWeight.w800))),Icon(supports?Icons.check_circle_rounded:Icons.error_outline,color:supports?AppColors.green:AppColors.red)])),
    const SizedBox(height:18),_title('فئات الشحن السريع'),const SizedBox(height:8),if(quick.isEmpty)Text('يمكنك إدخال المبلغ يدوياً',textAlign:TextAlign.right,style:TextStyle(color:AppColors.text3,fontSize:11))else Wrap(spacing:8,runSpacing:8,alignment:WrapAlignment.end,children:quick.map((x){final v=x['amount_yer']??x['amount']??x['value'];return OutlinedButton(onPressed:()=>setState(()=>amount.text='$v'.replaceAll('.0','')),child:Text('$v ر.ي'));}).toList()),
    const SizedBox(height:18),_title('المبلغ'),const SizedBox(height:7),TextField(controller:amount,onChanged:(_)=>setState((){}),keyboardType:const TextInputType.numberWithOptions(decimal:true),textAlign:TextAlign.center,style:const TextStyle(fontSize:25,fontWeight:FontWeight.w800),decoration:const InputDecoration(hintText:'0',suffixText:'ر.ي',prefixIcon:Icon(Icons.payments_rounded))),
    if(error!=null)Padding(padding:const EdgeInsets.only(top:14),child:Text(error!,textAlign:TextAlign.right,style:const TextStyle(color:AppColors.red,fontSize:12))),
    const SizedBox(height:18),ElevatedButton.icon(onPressed:(paying||network==null||!supports||(double.tryParse(amount.text)??0)<=0)?null:submit,icon:paying?const SizedBox(width:18,height:18,child:CircularProgressIndicator(strokeWidth:2)):const Icon(Icons.send_rounded),label:Text(paying?'جاري تنفيذ الشحن...':'شحن الرصيد'),style:ElevatedButton.styleFrom(minimumSize:const Size.fromHeight(54),shape:RoundedRectangleBorder(borderRadius:BorderRadius.circular(13))))]));}
  Widget _title(String s)=>Align(alignment:Alignment.centerRight,child:Text(s,style:const TextStyle(fontSize:14,fontWeight:FontWeight.w800)));
}
