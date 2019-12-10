# Roi Filemanager - PHP

Roi Filemanager provides simple API to manage your media library. The storage of files are handled by [Flysystem](https://flysystem.thephpleague.com/), so you can use of number of adapters provided or use your own. This package is framework agnostic.

All files and folder entries are recorded in the local database so that all of the expensive read requests are handled effeciently. The physical location of your files will be your choice. Flysystem supports various number of options to choose from S3, SFTP, Dropbox, Azure etc... 


# Usage

## Initialize
    $filemanager = new \Iamroi\RoiFilemanager;

	$config['db'] = [  
	  'host' => 'DB_HOST',  
	  'database' => 'DB_DATABASE',  
	  'username' => 'DB_USERNAME',  
	  'password' => 'DB_PASSWORD',  
	];  
	
	$config['publicRoot'] = "/absoulte/path/to/your/public/directory";  
	
	// optional
	$config['fileManagerDir'] = 'media'; // all of the filemanager files will be stored under this folder
  
	$fileManager = new RoiFileManager($config);
	
## Upload
	$response = $fileManager->upload('your/destination/path/here');
	
### Using different upload name

default uploaded file name is 'file'
to change the uploaded file name for example `$_FILES['name']['my_precious_file']`

	$response = $fileManager->upload('your/destination/path/here', 'my_precious_file');

## List

	$response = $fileManager->list($path, $search, $page, $limit);
	
## Delete

This will delete the entry from the local database and also from the physical location of your choice. `$path` can be file path, folder path, array of file paths or array of folder paths
	
	$response = $fileManager->delete($path);
