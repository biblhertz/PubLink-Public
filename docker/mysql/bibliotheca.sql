-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               8.0.32 - MySQL Community Server - GPL
-- Server OS:                    Win64
-- HeidiSQL Version:             12.15.0.7171
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Dumping database structure for bibliotheca
CREATE DATABASE IF NOT EXISTS `bibliotheca` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `bibliotheca`;

-- Dumping structure for table bibliotheca.canvas_publication
CREATE TABLE IF NOT EXISTS `canvas_publication` (
  `id` int NOT NULL AUTO_INCREMENT,
  `canvas` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `manifest` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_details_id` int NOT NULL DEFAULT (0),
  PRIMARY KEY (`id`),
  KEY `FK_canvas_publication_user_details` (`user_details_id`),
  CONSTRAINT `FK_canvas_publication_user_details` FOREIGN KEY (`user_details_id`) REFERENCES `user_details` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table bibliotheca.canvas_publication: ~0 rows (approximately)

-- Dumping structure for table bibliotheca.file
CREATE TABLE IF NOT EXISTS `file` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `path` mediumtext NOT NULL,
  `type` varchar(255) DEFAULT NULL,
  `size` varchar(20) DEFAULT NULL,
  `timestamp` timestamp NULL DEFAULT NULL,
  `file_type_id` int DEFAULT NULL,
  `user_details_id` int NOT NULL DEFAULT '1',
  `thumbnail_path` varchar(255) NOT NULL DEFAULT '1',
  `published_url` varchar(2048) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_file_user_details` (`user_details_id`),
  KEY `FK_file_file_type` (`file_type_id`),
  CONSTRAINT `FK_file_file_type` FOREIGN KEY (`file_type_id`) REFERENCES `file_type` (`id`),
  CONSTRAINT `FK_file_user_details` FOREIGN KEY (`user_details_id`) REFERENCES `user_details` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=507 DEFAULT CHARSET=latin1;

-- Dumping data for table bibliotheca.file: ~0 rows (approximately)

-- Dumping structure for table bibliotheca.file_type
CREATE TABLE IF NOT EXISTS `file_type` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('document','image','object') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'document',
  `thumbnail` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Types of files that the system accomodates\r\nThis is linked to tasks - tasks have file types that they operate on';

-- Dumping data for table bibliotheca.file_type: ~20 rows (approximately)
REPLACE INTO `file_type` (`id`, `name`, `description`, `type`, `thumbnail`) VALUES
	(1, 'pdf', 'Portable Document Format', 'document', 'pdf.png'),
	(2, 'doc', 'Word document', 'document', 'doc.png'),
	(3, 'docx', 'Word document', 'document', 'doc.png'),
	(4, 'xml', 'eXtensible Markup Language document', 'document', 'xml.png'),
	(5, 'png', 'Portalble Network Graphics image format', 'image', NULL),
	(6, 'jpg', 'Image Format', 'image', NULL),
	(7, 'jpeg', 'Image Format', 'image', NULL),
	(8, 'ppm', 'PPM', 'image', NULL),
	(9, 'tif', 'TIF', 'image', NULL),
	(10, 'tiff', 'TIF', 'image', NULL),
	(11, 'html', 'Hypertext Markup Language', 'document', 'html.png'),
	(12, 'csv', 'Comma Separated Values', 'document', 'csv.png'),
	(13, 'object', 'Serializable Object', 'object', 'obj.png'),
	(14, 'jp2', 'JPEG 2000 image type', 'image', NULL),
	(15, 'txt', 'ASCII text file', 'document', 'txt.png'),
	(16, 'log', 'Log File ', 'document', 'txt.png'),
	(17, 'JATS xml', 'JATS XML Document', 'document', 'jats_xml.jpg'),
	(18, 'OJS xml', 'OJS XML Document', 'document', 'ojs_xml.png'),
	(19, 'bib', 'Bibtex Reference Format', 'document', 'bibtex.png'),
	(20, 'json', 'JSON format', 'document', 'json.png');

-- Dumping structure for table bibliotheca.image_annotation
CREATE TABLE IF NOT EXISTS `image_annotation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_details_id` int NOT NULL DEFAULT '0' COMMENT 'annotation owner',
  `canvas` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `annotation` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `annotation_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `manifest` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'manifest address',
  `fragment_selector` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created` bigint DEFAULT NULL COMMENT 'timestamp when created',
  `image_root` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `edited` int DEFAULT '0' COMMENT 'Flag for edited by mirador to enable synchronization',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='image annotations ';

-- Dumping data for table bibliotheca.image_annotation: ~0 rows (approximately)

-- Dumping structure for table bibliotheca.intranet_scripts
CREATE TABLE IF NOT EXISTS `intranet_scripts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `user_group_id` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `user_group_id` (`user_group_id`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=latin1;

-- Dumping data for table bibliotheca.intranet_scripts: ~58 rows (approximately)
REPLACE INTO `intranet_scripts` (`id`, `name`, `user_group_id`) VALUES
	(1, 'index.html', 1),
	(2, 'profile.html', 1),
	(3, 'user.html', 20),
	(8, 'jobQueueService.php', 1),
	(9, 'fileService.php', 1),
	(10, 'deleteFileService.php', 1),
	(11, 'checkBoxFileListService.php', 1),
	(12, 'checkLogInService.php', 1),
	(13, 'uploadFileService.php', 1),
	(14, 'logInService.php', 1),
	(15, 'userList.html', 20),
	(16, 'userSessionStats.html', 20),
	(17, 'pdfExtract.php', 1),
	(18, 'taskControl.html', 20),
	(19, 'userUsage.html', 20),
	(20, 'userStats.html', 20),
	(21, 'latestSessions.html', 20),
	(22, 'checkJobService.php', 1),
	(23, 'logViewer.html', 20),
	(24, 'logService.php', 20),
	(25, 'myTasks.html', 1),
	(26, 'myFiles.html', 1),
	(27, 'image.html', 1),
	(28, 'jatsToOJS.php', 1),
	(29, 'createArticle.php', 1),
	(30, 'article.html', 1),
	(31, 'transformArticle.php', 1),
	(32, 'myImages.html', 1),
	(33, 'imageJpg.php', 1),
	(34, 'articleToJATS.php', 1),
	(35, 'articleToDataCite.php', 1),
	(36, 'logfileService.php', 1),
	(37, 'addUser.html', 20),
	(38, 'referenceHandler.php', 1),
	(39, 'referenceChecker.php', 1),
	(40, 'referenceChecker.php', 1),
	(41, 'uploadJATS.html', 1),
	(42, 'jatsMenuService.php', 1),
	(43, 'referenceCollection.html', 1),
	(44, 'refCheckService.php', 1),
	(45, 'keycloak.html', 1),
	(46, 'annotationTools.html', 1),
	(47, 'annotationList.html', 1),
	(48, 'bibjatsReferences.php', 1),
	(49, 'jatsToIndesign.php', 1),
	(50, 'bibtexToHtml.php', 1),
	(51, 'checkAnnotationService.php', 1),
	(52, 'annotationCanvas.html', 1),
	(53, 'superUserControl.html', 20),
	(54, 'newReferenceCollection.html', 1),
	(55, 'reference.html', 1),
	(56, 'refService.php', 1),
	(57, 'refUpdateService.php', 1),
	(58, 'citeService.php', 1),
	(59, 'newReferenceChecker.php', 1),
	(60, 'xmlToManifest.php', 1),
	(61, 'jobRunningService.php', 1),
	(62, 'publishManifest.php', 1),
	(63, 'iiif_generator.html', 1),
	(64, 'removeManifest.php', 1);

-- Dumping structure for table bibliotheca.job
CREATE TABLE IF NOT EXISTS `job` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `task_name` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `parameters` longtext CHARACTER SET latin1 COLLATE latin1_swedish_ci,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `user_details_id` int DEFAULT NULL,
  `task_id` int DEFAULT NULL,
  `status` longtext CHARACTER SET latin1 COLLATE latin1_swedish_ci,
  `output_file_id` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 ROW_FORMAT=COMPACT;

-- Dumping data for table bibliotheca.job: ~0 rows (approximately)
DELETE FROM `job`;

-- Dumping structure for table bibliotheca.job_log
CREATE TABLE IF NOT EXISTS `job_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `task_name` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  `parameters` longtext CHARACTER SET latin1 COLLATE latin1_swedish_ci,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `user_details_id` int DEFAULT NULL,
  `task_id` int DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `error_message` varchar(255) DEFAULT NULL,
  `time_taken` varchar(255) DEFAULT NULL,
  `log_file` varchar(255) DEFAULT NULL,
  `output_file_id` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 ROW_FORMAT=COMPACT;

-- Dumping data for table bibliotheca.job_log: ~0 rows (approximately)

-- Dumping structure for table bibliotheca.serialized_object
CREATE TABLE IF NOT EXISTS `serialized_object` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `object` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('Article','Reference Collection') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Article',
  `user_details_id` int NOT NULL,
  `timestamp` timestamp NULL DEFAULT NULL,
  `file_id` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK_serialized_object_user_details` (`user_details_id`),
  KEY `FK_serialized_object_file` (`file_id`),
  CONSTRAINT `FK_serialized_object_file` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`),
  CONSTRAINT `FK_serialized_object_user_details` FOREIGN KEY (`user_details_id`) REFERENCES `user_details` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='Types of files that the system accomodates\r\nThis is linked to tasks - tasks have file types that they operate on';

-- Dumping data for table bibliotheca.serialized_object: ~0 rows (approximately)

-- Dumping structure for table bibliotheca.session_variable
CREATE TABLE IF NOT EXISTS `session_variable` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` int DEFAULT NULL,
  `variable_name` varchar(64) DEFAULT NULL,
  `variable_value` text,
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- Dumping data for table bibliotheca.session_variable: ~0 rows (approximately)

-- Dumping structure for table bibliotheca.settings
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '0',
  `value` varchar(50) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Dumping data for table bibliotheca.settings: ~0 rows (approximately)

-- Dumping structure for table bibliotheca.task
CREATE TABLE IF NOT EXISTS `task` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Name Here',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_handler` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_text` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `input_type` enum('Single File','Multiple File','Object','Single File or Single Object','Single File and Single Object') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Single File',
  `public` tinyint DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='represents a task in the system, i.e. a standalone unit that performs something';

-- Dumping data for table bibliotheca.task: ~7 rows (approximately)
REPLACE INTO `task` (`id`, `name`, `description`, `action_handler`, `action_text`, `input_type`, `public`) VALUES
	(1, 'PDF Extract Image Tool', 'Extracts the images from a PDF document and stores them in your file store', './handlers/pdfExtract.php', 'Extract Images', 'Single File', 1),
	(5, 'Create JPEG 2000', 'Transforms an image file to a jpeg 2000 image format file.', './handlers/imageJpg.php', 'Image Transform', 'Single File', 1),
	(9, 'Bibtex - JATS reference Tool ', 'Updates a JATS XML document with a more detailed list of references containing information added from a bibtex file. The references must have the same id in both documents for this to work. Select one JATS file and the corresponding bibtex file to run the tool.', './handlers/bibjatsReferences.php', 'Update References', 'Multiple File', 1),
	(10, 'JATS to Indesign Tool', 'Converts a JATS document to an indesign XML document', './handlers/jatsToIndesign.php', 'Convert', 'Single File', 1),
	(11, 'Bibtex to html Tool', 'Converts a bibtext document to a html list of references styled by the BH CSL style', './handlers/bibtexToHtml.php', 'Create Html', 'Single File', 1),
	(13, 'New Reference Checker', 'Reference checker tool', './handlers/newReferenceChecker.php', 'Check Refrences', 'Object', 0),
	(14, 'XML to IIIF Manifest', 'Generates an IIIF Presentation API 3.0 manifest from a JATS XML article. Extracts <fig> elements from <body>, fetches image dimensions from IIIF info.json endpoints, and writes a multi-canvas manifest JSON file ready for upload to the annotation server.', './handlers/xmlToManifest.php', 'Generate Manifest', 'Multiple File', 0);

-- Dumping structure for table bibliotheca.task_file_type
CREATE TABLE IF NOT EXISTS `task_file_type` (
  `id` int NOT NULL AUTO_INCREMENT,
  `file_type_id` int DEFAULT NULL,
  `task_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK__file_type` (`file_type_id`),
  KEY `FK__task_id` (`task_id`),
  CONSTRAINT `FK__file_type` FOREIGN KEY (`file_type_id`) REFERENCES `file_type` (`id`),
  CONSTRAINT `FK__task_id` FOREIGN KEY (`task_id`) REFERENCES `task` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='many to many relationship linking files and tasks together\r\na task can have 0,1 or many file types as input and file_type can have 0,1 or many tasks associated with it';

-- Dumping data for table bibliotheca.task_file_type: ~14 rows (approximately)
REPLACE INTO `task_file_type` (`id`, `file_type_id`, `task_id`) VALUES
	(6, 7, 5),
	(7, 6, 5),
	(8, 8, 5),
	(9, 9, 5),
	(10, 10, 5),
	(15, 1, 1),
	(16, 5, 5),
	(17, 19, 9),
	(18, 17, 9),
	(20, 17, 10),
	(21, 19, 11),
	(23, 13, 13),
	(24, 17, 14),
	(26, 20, 14);

-- Dumping structure for table bibliotheca.user_details
CREATE TABLE IF NOT EXISTS `user_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL COMMENT 'username',
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` longtext CHARACTER SET latin1 COLLATE latin1_swedish_ci,
  `user_group_id` int NOT NULL DEFAULT '1',
  `current` char(1) DEFAULT 't',
  `token` longtext CHARACTER SET latin1 COLLATE latin1_swedish_ci COMMENT 'orcid ID token',
  `first_login` int DEFAULT '1' COMMENT 'first user login',
  `login_type` enum('local','orcid','keycloak') CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT 'local' COMMENT 'user login type',
  `password` tinytext,
  PRIMARY KEY (`id`),
  KEY `user_group_id` (`user_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- Dumping data for table bibliotheca.user_details: ~0 rows (approximately)

-- Dumping structure for table bibliotheca.user_details_canvas_annotation
CREATE TABLE IF NOT EXISTS `user_details_canvas_annotation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_details_id` int NOT NULL,
  `canvas` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `owner_id` int DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  KEY `FK__user_details` (`user_details_id`) USING BTREE,
  KEY `FK_user_details_canvas_annotation_user_details` (`owner_id`),
  CONSTRAINT `FK_user_details_canvas_annotation_user_details` FOREIGN KEY (`owner_id`) REFERENCES `user_details` (`id`),
  CONSTRAINT `user_details_canvas_annotation_ibfk_2` FOREIGN KEY (`user_details_id`) REFERENCES `user_details` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC COMMENT='implements many to many relationship between user details and image annotation allowing sharing';

-- Dumping data for table bibliotheca.user_details_canvas_annotation: ~0 rows (approximately)

-- Dumping structure for table bibliotheca.user_details_task
CREATE TABLE IF NOT EXISTS `user_details_task` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_details_id` int NOT NULL,
  `task_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_task` (`user_details_id`, `task_id`),
  KEY `FK__user_details` (`user_details_id`),
  KEY `FK__task` (`task_id`),
  CONSTRAINT `FK__task` FOREIGN KEY (`task_id`) REFERENCES `task` (`id`),
  CONSTRAINT `FK__user_details` FOREIGN KEY (`user_details_id`) REFERENCES `user_details` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='implements many to many relationship between user details and tasks';

-- Dumping data for table bibliotheca.user_details_task
INSERT IGNORE INTO `user_details_task` (`user_details_id`, `task_id`) SELECT 1, `id` FROM `task`;

-- Dumping structure for table bibliotheca.user_group
CREATE TABLE IF NOT EXISTS `user_group` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='user groups';

-- Dumping data for table bibliotheca.user_group: ~2 rows (approximately)
REPLACE INTO `user_group` (`id`, `name`) VALUES
	(1, 'User'),
	(20, 'Super User');

-- Dumping structure for table bibliotheca.user_intranet_log
CREATE TABLE IF NOT EXISTS `user_intranet_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_details_id` int DEFAULT NULL,
  `user_session_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `timestamp` timestamp NULL DEFAULT NULL,
  `script` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `params` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,
  `ip_address` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `client` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT NULL,
  `security_breach` char(1) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci DEFAULT '0',
  `memory_usage` double DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_details_id` (`user_details_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- Dumping data for table bibliotheca.user_intranet_log: ~0 rows (approximately)

-- Dumping structure for table bibliotheca.user_session
CREATE TABLE IF NOT EXISTS `user_session` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ascii_session_id` varchar(32) DEFAULT NULL,
  `logged_in` char(1) DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `last_impression` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created` timestamp NULL DEFAULT NULL,
  `user_agent` mediumtext CHARACTER SET latin1 COLLATE latin1_swedish_ci,
  `ip` varchar(100) CHARACTER SET latin1 COLLATE latin1_swedish_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- Dumping data for table bibliotheca.user_session: ~0 rows (approximately)

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
