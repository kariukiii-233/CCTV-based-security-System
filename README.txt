CCTV-BASED UNIVERSITY SECURITY SYSTEM  (PHP + MySQL, XAMPP)
=============================================================

SET UP (5 steps)
1. Open XAMPP Control Panel and Start both Apache and MySQL.
2. Copy this whole "cctv" folder into  C:\xampp\htdocs\
   (so you have C:\xampp\htdocs\cctv\login.php).
3. Open your browser at:   http://localhost/cctv/install.php
4. Leave "Built-in database" selected and click Install. It creates the
   database, tables and both accounts. No MySQL password and no phpMyAdmin
   needed. (Choose the MySQL option only if you want to use MySQL.)
5. Click "Go to the login page".

LOGINS
   Administrator:       admin     /  admin123      (full access)
   Security personnel:  security  /  security123   (cameras, incidents, recordings, alerts)
Change both passwords after first login: Settings > Change password.

CONNECTING REAL CAMERAS (live video, no demo footage)
The system contains no fake video. Every picture you see comes from a real camera.
 - CAM 01 uses your laptop's camera: Live monitoring > CAM 01 > click Allow.
 - CAM 02-06 start as "Offline / not connected". Connect each one as below.

 USE A PHONE AS A CAMERA (Android, free):
  1. Install the app "IP Webcam" from the Play Store. Put the phone on the same Wi-Fi as the computer.
  2. In the app, scroll down and tap "Start server". It shows an address such as
         http://192.168.1.50:8080
     Open that address in the computer's browser to check you can see the phone's picture.
  3. In the system, log in as admin > Cameras > Edit the camera (for example CAM 02) and fill in
         Camera address ..... http://192.168.1.50:8080/video
         Status ............. Online
     then Save. Live monitoring > CAM 02 now shows the phone's live video.
  4. For 24/7 recording of that camera: install ffmpeg (DEPLOYMENT.txt) and double-click
     start-recorder.bat. The footage appears on the Recordings page.
  (No ffmpeg? Put the same address in "Direct stream URL" instead: you get live view, no recording.)
  Use one phone per location. Keep the phones plugged in. iPhone: use an app that gives an
  http "MJPEG" address.

 USE A REAL CCTV / IP CAMERA: put its rtsp:// address in "Camera address" (see DEPLOYMENT.txt).

IF THE INSTALLER SAYS "Access denied"
You picked the MySQL option. Go back and choose "Built-in database".

TO INSTALL AGAIN FROM SCRATCH
Delete install.lock and config.php from the cctv folder, then open install.php.
(This resets all data.)

BACKUP AND WHERE THE DATA IS
Admin > Settings > "Download backup" saves all system data to your Downloads folder.
 - Built-in database: data is in  cctv\data\cctv.sqlite . To restore a backup, close the
   browser, rename the downloaded .sqlite file to cctv.sqlite and put it in the data folder.
 - MySQL option: data is in the database cctv_system. Restore with phpMyAdmin > Import.
Video footage is separate: cctv\recordings\ (one folder per camera).

REAL CAMERAS + 24/7 RECORDING
See DEPLOYMENT.txt. In short: install ffmpeg, put each camera's rtsp:// address in
Cameras > Edit, then double-click start-recorder.bat and leave it open.
Admin > "System health" shows the recorder, storage and every camera.

FILES
login.php / logout.php  sign in and out      app.php  the whole interface
api.php   server functions (role checks)     db.php   database connection + settings
install.php  one-time setup                  database.sql  tables + sample data
recorder.php + start-recorder.bat  24/7 recording    stream.php  live view of RTSP cameras
play.php  plays saved footage
backup.php  data backup download
DEPLOYMENT.txt  real-world setup guide
