# Google Drive™ Driver for TYPO3's File Abstraction Layer (FAL)

**This extension enables you to use files from Google Drive in your TYPO3 projects.**

> **Please note:** This extension is still in beta and might not work 100% as expected. Feel free to contribute.

* View and modify files and folders from Google Drive in the Filelist module
* Add images to content elements and create links to files for download.
* Google Documents, Spreadsheets, and Slides are listed multiple times, once for each of their export formats (e.g. PDF or HTML).
 
![Corresponding folder list in Google Drive and TYPO3 Filelist](https://github.com/mabolek/google_drive_fal/blob/master/Documentation/Images/drive-and-typo3-example.png?raw=true)

## Installation

1. Install the extension using Composer: `composer req mabolek/google-drive-fal`
2. Activate the extension in the Extensions module or by running `vendor/bin/typo3 extension:activate google_drive_fal`

## Configuration

### Set up Google OAuth Client ID

This a big topic and the detailes are described in detail in [Google's documentation](https://console.developers.google.com/).

Here's the simple way:

1. Click on the Enable Google Docs API button at https://developers.google.com/drive/api/v3/quickstart/js
2. Download the Client Configuration and save it on the server
3. Run `vendor/bin/typo3 googledrive:setup [PATH-TO-FILE]`
4. You will be prompted to open a URL in your browser. Follow the authentication process in the browser.
5. Paste in the entire resulting URL when you are prompted to enter the verification link.
6. The extension will extract the verification code from the URL and (hopefully) tell you it has been successfully configured.

### Create a File Storage Record

1. Create a new File Storage Record in the root page.
2. Give it a name.
3. Choose Google Drive™ from the Driver menu.
4. If you have a lot of files on your drive, we recommend that you supply a root folder identifier so Filelist doesn't have to process them all.
5. If you do not supply one, the driver will create its own folder for manipulated and temporary images etc. This folder cannot be on the Google Drive itself, so the default is in a folder within `typo3temp/assets/`.
6. Save and close.

![Example file storage record](https://github.com/mabolek/google_drive_fal/blob/master/Documentation/Images/create-file-storage-record.png?raw=true)

If you go to the Filelist module, you should see your Google Drive there.

## Limitations

Your drive is not publicly available
: TYPO3 will have to download each file to process it. That will work OK for images that are processed, because they are stored elsewhere, but file downloads may be slower than usual. The plan is to add a caching mechanism to make it faster to serve files publicly.

## Contribution

This is a hobby project. Feel free to join the project or contibute pull requests of all kinds.

## Thanks and Credits

This extension was created by Mathias Bolt Lesniak on his free time. 

The original `Client` and `SetupCredentialsCommand` classes came from Georg Ringer's [google_docs_content](https://github.com/georgringer/google_docs_content) extension. That extension was also a major inspiration behind this project, and hopefully this driver will make that extension even better.

The `GoogleDriveDriver` class would not have been possible without the eye-opening code in Anders und sehr's [Amazon S3 Driver](https://github.com/andersundsehr/aus_driver_amazon_s3).

## Trademarks

Google Drive™ and the Google Drive™ logo are trademarks of Google Inc.
