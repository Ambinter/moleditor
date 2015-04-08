What is MolEditor?
------------------

[MolEditor][1] is a free software to create, edit and export a simple chemical base online. It is developed by Ambinter cheminformatics.

Requirements
------------

NOTE : MolEditor has only be tested on GNU/Linux. It is also optimized for Firefox 25+. 

* MolEditor is based on [Silex][2] Framework, which supported PHP 5.3.3 and later.
* You also need to install [openbabel][3] to compute descriptor columns.
* Sqlite3 is required
* A version of the glibc library later that 2.14 could also be required on UNIX systems to deal with SMILES exports.

Installation
------------

Just download MolEditor on your server (see requirements first) and it should work on http://localhost/moleditor
Configure your php.ini with upload_max_filesize=50M.

If necessary, run localhost/moleditor/web/install/add-filters and localhost/moleditor/web/install/update-scheme/{version}
Directories /moleditor/src/db, /moleditor/src/tmp, /moleditor/src/workflow must be accessible for writing (run a chmod 777 if necessary)

[1]: http://www.ambinter.com/moleditor
[2]: http://silex.sensiolabs.org
[3]: http://openbabel.org
