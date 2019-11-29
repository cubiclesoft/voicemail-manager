Voicemail Manager
=================

Voicemail Manager works with Twilio-compatible systems to automatically route incoming calls to a voicemail queue.  It has flexible management options available through phone and web interfaces.

[![Voicemail Manager Overview and Live Demo video](https://user-images.githubusercontent.com/1432111/69885071-99082c00-1298-11ea-93e2-6e22433e5c98.png)](https://www.youtube.com/watch?v=Mo4lb8RusE4 "Voicemail Manager Overview and Live Demo")

[![Donate](https://cubiclesoft.com/res/donate-shield.png)](https://cubiclesoft.com/donate/)

Features
--------

* Customizable messaging options.
* Voicemail queue and per-user assignment queues for streamlined handling of incoming voicemail.
* Phone and web interfaces for admins.
* Sends SMS and/or email notifications to configured phones whenever a new voicemail arrives.
* Keeps personal phone numbers private.
* Easily block abusive numbers.
* Calling and call forwarding options on a per-phone number basis.
* Works with [Twilio](https://www.twilio.com/) and Twilio-compatible services (i.e. TwiML) for rapid deployment.
* Has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your environment.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Getting Started
---------------

Download or clone the latest software release.  Transfer the files to a web server directory of your choosing.

Change the owner of the 'files' directory to be [the web server user](https://cubicspot.blogspot.com/2017/05/secure-web-server-permissions-that-just.html) (e.g. 'chown www-data /path/to/files').

In the root of the directory where the files were uploaded, create a new file called `settings.php` and put the following code into it:

```php
<?php
	// The phones that are allowed to access the voicemail manager.  Phone numbers must be in E.164 format.
	// When 'admin' is true, the user of that phone can make configuration changes in the web interface.
	// The 'notify' option can be 'sms', 'email', or 'all'.
	$allowedphones = array(
		"+13145551212" => array("name" => "Your Name", "email" => "you@yourdomain.com", "admin" => true, "notify" => "all"),
	);

	// SMTP options when sending email.
	// See:  https://github.com/cubiclesoft/ultimate-email/blob/master/docs/smtp.md
	$emailfrom = "\"Voicemail Manager\" <webmaster@yourdomain.com>";
	$emailoptions = array();

	// Twilio Account Sid and Token.
	$twilio_sid = "YOUR_ACCOUNT_SID";
	$twilio_token = "YOUR_ACCOUNT_TOKEN";
	$twilio_apibase = false;
?>
```

Adjust the various options above for your needs.  When not using Twilio, the `$twilio_apibase` will need to be set to point at the service's Twilio-compatible API.  The API is used to send SMS messages and also retrieve and delete voicemail recordings so long-term storage charges aren't incurred.

Next, purchase a phone number through [Twilio](https://www.twilio.com/) or a Twilio-compatible service.  Note that only non-trial Twilio accounts are known to be fully supported at this time.

Point the newly purchased phone number's Voice webhook to the URL where this software is installed.  Also, remove the default SMS webhook to disable SMS for that number.

Finally, call the phone number from an allowed phone.  If all goes well, a menu of options will be spoken.  Press '4' to receive a link to the management interface via SMS and email.  Once inside the management interface, configure various options as desired.

Use Cases
---------

This tool is great for:

* Small business owners who don't want to give out personal or office phone numbers.  By default, customers who call the number are dropped to voicemail, notifications about the new voicemail are sent, which allows their call to be returned in a timely fashion.  Multiple employees can be set up to access the system to handle incoming messages as their time permits to generally keep the voicemail queue empty while simultaneously keeping important meetings interruption-free.
* Quickly setting up a temporary phone number to use as a help line for a contest or event where multiple people need to be set up to handle the calls that come in.
* Keeping a personal phone number private for a variety of reasons.  Give a throwaway phone number to people you don't trust yet with your personal phone number.
* Building a call center where you don't want to have customers wait on the line for 30+ minutes to talk to a human.

Twilio Alternatives
-------------------

Note that Twilio can get pricey pretty quick.  It's great for low-volume needs but the costs add up fast.

* [SignalWire](https://signalwire.com/) - Half the cost with similar API and TwiML interfaces.  U.S. and Canada only.  Note that, as of this writing in 2019, certain features that Twilio supports are not implemented or not implemented properly in SignalWire.  Therefore, this tool may not function on SignalWire until their interface becomes sufficiently compatible.
