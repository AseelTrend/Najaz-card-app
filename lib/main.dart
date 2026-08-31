import 'package:flutter/material.dart';
import 'screens/splash_screen.dart';

void main() {
  runApp(const NjazApp());
}

class NjazApp extends StatelessWidget {
  const NjazApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'نجاز كارد',
      debugShowCheckedModeBanner: false,
      locale: const Locale('ar'),
      // دعم العربية وواجهة من اليمين لليسار بدون أي حزم إضافية
      localizationsDelegates: [
        DefaultMaterialLocalizations.delegate,
        DefaultWidgetsLocalizations.delegate,
        DefaultCupertinoLocalizations.delegate,
      ],
      supportedLocales: const [Locale('ar'), Locale('en')],
      theme: ThemeData(
        useMaterial3: true,
        brightness: Brightness.dark,
        scaffoldBackgroundColor: const Color(0xFF090D1A),
        colorScheme: const ColorScheme.dark(
          primary: Color(0xFF3B82F6),
          surface: Color(0xFF0E1525),
        ),
      ),
      builder: (context, child) {
        // يفرض اتجاه RTL على كامل التطبيق بغض النظر عن لغة الجهاز
        return Directionality(textDirection: TextDirection.rtl, child: child!);
      },
      home: const SplashScreen(),
    );
  }
}
