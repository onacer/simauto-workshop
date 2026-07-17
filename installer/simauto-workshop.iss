#define MyAppName "SIM Auto Workshop"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "SIM Auto"
#define MyAppExeName "SIMAutoWorkshop.cmd"
#define SourceRoot "..\dist\desktop\app"

[Setup]
AppId={{9F8AE516-6776-47A0-AE92-9B6D70D0D491}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\SIM Auto Workshop
DefaultGroupName=SIM Auto Workshop
DisableProgramGroupPage=yes
OutputDir=..\dist
OutputBaseFilename=SIMAutoWorkshop-Setup
Compression=lzma2
SolidCompression=yes
ArchitecturesAllowed=x64
ArchitecturesInstallIn64BitMode=x64
PrivilegesRequired=admin

[Languages]
Name: "french"; MessagesFile: "compiler:Languages\French.isl"

[Files]
Source: "{#SourceRoot}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{autoprograms}\SIM Auto Workshop"; Filename: "{app}\{#MyAppExeName}"; WorkingDir: "{app}"
Name: "{autodesktop}\SIM Auto Workshop"; Filename: "{app}\{#MyAppExeName}"; WorkingDir: "{app}"; Tasks: desktopicon

[Tasks]
Name: "desktopicon"; Description: "Créer un raccourci sur le bureau"; GroupDescription: "Raccourcis"; Flags: unchecked

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Lancer SIM Auto Workshop"; Flags: nowait postinstall skipifsilent

[Code]
function IsWebView2Installed(): Boolean;
var
  Version: String;
begin
  Result :=
    RegQueryStringValue(HKLM, 'SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F1E7B7F4-6D50-4B3F-8B72-9F4F89CE41E0}', 'pv', Version) or
    RegQueryStringValue(HKCU, 'SOFTWARE\Microsoft\EdgeUpdate\Clients\{F1E7B7F4-6D50-4B3F-8B72-9F4F89CE41E0}', 'pv', Version);
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  Installer: String;
  ResultCode: Integer;
begin
  if CurStep = ssPostInstall then begin
    Installer := ExpandConstant('{app}\prereq\MicrosoftEdgeWebView2RuntimeInstallerX64.exe');
    if (not IsWebView2Installed()) and FileExists(Installer) then begin
      Exec(Installer, '/silent /install', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
    end;
  end;
end;

procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if CurUninstallStep = usPostUninstall then begin
    if MsgBox('Voulez-vous supprimer les donnees SIM Auto Workshop dans AppData ?' + #13#10 +
      'Choisissez Non pour conserver la base et les sauvegardes.', mbConfirmation, MB_YESNO) = IDYES then begin
      DelTree(ExpandConstant('{userappdata}\SIMAutoWorkshop'), True, True, True);
    end;
  end;
end;
