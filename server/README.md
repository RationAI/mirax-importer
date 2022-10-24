# Server

a simple HTTP API for uploading WSI and running processing tasks. 
Edit ``index.php`` and define desired constants so that the 
system works as intended and stores logs and files to 
desired folders.

Modify ``job.sh`` to perform custom stuff with the uploaded data.


The trickiest part is the server setup so that all rights and configuration limits
are set up right, namely
 - modify rights for ``jobs.sh`` so that it is executable (and readable) under the user your server acts as
 - setup your folders so that they are readable and writeable for the server
   - the directory for ``$log_file``
   - the ``$upload_root`` dir
   - possibly other directories as your script needs
 - setup the ``php.ini`` so that necessary limits are high enough
   - upload file count is max 1, usually
   - upload size should be 2GB or even more
   - max post size should equal to the upload size
 - setup your server so that the POST content size is not limited, or limited to
the upload size
   - for example apache uses ``LimitRequestBody`` with default 1GB limit (as of `v2.4`)