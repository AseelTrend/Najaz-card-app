import 'package:flutter/material.dart';
import '../theme/app_colors.dart';
import '../services/storage_service.dart';
import 'login_screen.dart';
import 'home_screen.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});
  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    _checkLogin();
  }

  Future<void> _checkLogin() async {
    await Future.delayed(const Duration(milliseconds: 700));
    final loggedIn = await StorageService.isLoggedIn();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(MaterialPageRoute(
      builder: (_) => loggedIn ? const HomeScreen() : const LoginScreen(),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg,
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 88,
              height: 88,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                gradient: AppColors.balanceGradient,
                borderRadius: BorderRadius.circular(26),
                boxShadow: [
                  BoxShadow(color: AppColors.accentPurple.withOpacity(0.4), blurRadius: 24, offset: const Offset(0, 10)),
                ],
              ),
              child: const Icon(Icons.bolt_rounded, color: Colors.white, size: 46),
            ),
            const SizedBox(height: 20),
            const Text('نجاز كارد',
                style: TextStyle(color: AppColors.text, fontSize: 22, fontWeight: FontWeight.bold)),
            const SizedBox(height: 28),
            const CircularProgressIndicator(color: AppColors.primary),
          ],
        ),
      ),
    );
  }
}
