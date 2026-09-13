import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:google_fonts/google_fonts.dart';
import 'services/storage_service.dart';
import 'theme/app_colors.dart';
import 'screens/splash_screen.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // [FEATURE] تفعيل الوضع الليلي/النهاري فعلياً: نقرأ التفضيل المحفوظ
  // مسبقاً (نفس المفتاح "dark_mode" الذي تحفظه شاشة الإعدادات) قبل أول
  // رسم للواجهة، حتى يبدأ التطبيق مباشرة بالوضع الصحيح دون وميض.
  final savedDarkMode = await StorageService.getSetting('dark_mode');
  AppColors.mode.value = savedDarkMode != 'false';
  runApp(const NjazApp());
}

class NjazApp extends StatelessWidget {
  const NjazApp({super.key});

  @override
  Widget build(BuildContext context) {
    // [FEATURE] الاستماع لتبديل الوضع الليلي/النهاري (AppColors.mode) وإعادة
    // بناء التطبيق بالكامل من الجذر عند تغييره، حتى تنعكس الألوان الجديدة
    // فوراً على كل الشاشات المفتوحة.
    return ValueListenableBuilder<bool>(
      valueListenable: AppColors.mode,
      builder: (context, isDark, _) {
        return MaterialApp(
          title: 'نجاز كارد',
          debugShowCheckedModeBanner: false,
          locale: const Locale('ar'),
          localizationsDelegates: const [
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('ar'), Locale('en')],
          theme: ThemeData(
            useMaterial3: true,
            brightness: isDark ? Brightness.dark : Brightness.light,
            fontFamily: GoogleFonts.cairo().fontFamily,
            scaffoldBackgroundColor: AppColors.bg,
            colorScheme: isDark
                ? ColorScheme.dark(
                    primary: AppColors.primary,
                    secondary: AppColors.accentPurple,
                    surface: AppColors.card,
                  )
                : ColorScheme.light(
                    primary: AppColors.primary,
                    secondary: AppColors.accentPurple,
                    surface: AppColors.card,
                  ),
            appBarTheme: AppBarTheme(
              backgroundColor: AppColors.bg,
              foregroundColor: AppColors.text,
              elevation: 0,
              centerTitle: false,
            ),
            inputDecorationTheme: InputDecorationTheme(
              filled: true,
              fillColor: AppColors.card2,
              labelStyle: TextStyle(color: AppColors.text2),
              prefixIconColor: AppColors.text2,
              border: const OutlineInputBorder(
                borderRadius: BorderRadius.all(Radius.circular(14)),
                borderSide: BorderSide.none,
              ),
              enabledBorder: const OutlineInputBorder(
                borderRadius: BorderRadius.all(Radius.circular(14)),
                borderSide: BorderSide.none,
              ),
              focusedBorder: const OutlineInputBorder(
                borderRadius: BorderRadius.all(Radius.circular(14)),
                borderSide: BorderSide(color: AppColors.primary, width: 1.2),
              ),
              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
            ),
            bottomNavigationBarTheme: BottomNavigationBarThemeData(
              backgroundColor: Colors.transparent,
              elevation: 0,
              selectedItemColor: AppColors.primary,
              unselectedItemColor: AppColors.text2,
              type: BottomNavigationBarType.fixed,
            ),
            navigationBarTheme: NavigationBarThemeData(
              backgroundColor: AppColors.card,
              indicatorColor: AppColors.primary,
              labelTextStyle: const MaterialStatePropertyAll(
                TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
              ),
            ),
          ),
          home: const SplashScreen(),
        );
      },
    );
  }
}
