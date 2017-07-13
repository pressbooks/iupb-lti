# IU Pressbooks LTI

LTI Integration for Pressbooks at IU. Based on the Candela LTI integration from Lumen Learning (https://github.com/lumenlearning/candela-lti). 
Primary differences:

- Looks for a specified custom LTI parameter to use for the WordPress login id (instead of using the generated LTI user id)


## Requirements

Must have the core WordPress LTI plugin from Lumen Learning installed: https://github.com/lumenlearning/lti

This core LTI plugin requires the PHP OAuth module be installed. Our dev server is running PHP 5.6, which requires installation of older OAuth package (oauth-1.2.3, instead of current 2.x)

	sudo pecl install oauth-1.2.3

Activate the module by editing (or creating) the php.ini file at /etc/php/5.6/apache2/conf.d with the contents: 

	extension=oauth.so

Then restart Apache with:

	sudo service apache2 restart


## Publishing to IU's Unizin-hosted dev instance of Pressbooks.

### SSH shell connection

Public SSH key and IP address must be supplied to Unizin to whitelist an SSH connection to the server. Then SSH connections can be made from a shell terminal:

	ssh -i <path to your private key file> ubuntu@54.212.234.236

### SCP (file transfer)

Uploading files from your local machine to the server can be done using SCP (also requires the SSH key). Note that the local path is to the specific plugin's folder and the remote path is to the root of the plugins folder.

	scp -i <path your private key file> -r <local-path-of-the-specific-plugin-root> ubuntu@54.212.234.236:/var/www/iu-dev/wordpress/wp-content/plugins/