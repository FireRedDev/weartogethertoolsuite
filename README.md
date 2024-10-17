Erst Python (ink. Pip installieren) https://www.python.org/downloads/ dann dieses repository runterladen als ZIP und entpacken: https://github.com/FireRedDev/weartogethertoolsuite/tree/master

Option A Ausführen mit Python

Im Terminal ausführen:
```
pip3 install -r requirements.txt
```
(könnte auch statt pip3 pip sein)

mac: auf wear_together_toolsuite.py rechtsklick machen, öffnen mit auswählen, mit python launcher und ausführen

windows: terminal IN DEM ORDNER DER .py DATEI öffnen, 
```
Python3 wear_together_toolsuite.py
```
(oder statt Python3 Python)
   
Option B Für permanente Datei erstellen 
(Macos https://stackoverflow.com/questions/62451711/pyinstaller-icon-option-doesnt-work-on-mac) beachten, ein icon.icns ist notwendig)
Zum Ausführen bitte:  https://pyinstaller.org/en/stable/installation.html installieren mit " pip install pyinstaller " dann generate_exe.bat aus dem repository ausführen oder die kommandos darin ausführen im terminal (https://support.apple.com/de-de/guide/terminal/apd5265185d-f365-44cb-8b09-71a064a42125/mac) -> das erstellt eine ausführbare datei, die toolsuite
