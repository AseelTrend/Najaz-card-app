import 'package:flutter/material.dart';
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
    await Future.delayed(const Duration(milliseconds: 600));
    final loggedIn = await StorageService.isLoggedIn();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(MaterialPageRoute(
      builder: (_) => loggedIn ? const HomeScreen() : const LoginScreen(),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      backgroundColor: Color(0xFF090D1A),
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.bolt_rounded, color: Color(0xFF3B82F6), size: 64),
            SizedBox(height: 16),
            Text('نجاز كارد',
                style: TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold)),
            SizedBox(height: 24),
            CircularProgressIndicator(color: Color(0xFF3B82F6)),
          ],
        ),
      ),
    );
  }
}
