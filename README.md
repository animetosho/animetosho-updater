Anime Tosho Core Processing Scripts
====================

This is a series of PHP/Bash scripts which runs Anime Tosho’s updates, processing torrents, uploading them and various other tasks.

Note that these scripts weren't designed to be user friendly, and will likely require wading through a lot of code and frequent editing. A high level overview is provided below, but many details will be left as a code reading exercise.

## Overview

At a high level, automated processing is performed by executing PHP scripts periodically via cron. Other scripts are provided for administrative tasks and are executed manually.

### Summary by files

* **admin-\*.php**: administrative utility scripts, such as forcing a torrent to be processed, forcing a data update from the source site, etc
* **anifuncs.php**: contains core routines for AniDB tagging
* **cron\*.\***: scripts to be triggered by cron
  * **cron\*.sh** files typically trigger respective *cron\*.php* scripts
* **funcs.php**: general purpose functions loaded by all scripts
* **init.php**: initial loader script, used by all scripts
* **test-\*.php**: used for testing/debugging certain functions, may overlap somewhat with admin scripts
* **timebase.txt**: holds the timestamp from which to fetch torrents from; is updated by the *cron.php* script which adds torrents to Transmission, so must be writable (this file should probably be moved to the DB, but I never bothered with doing such)
* **3rdparty/**: various libraries I didn’t write, but may have updated. Also includes a cloudscraper proxy service (unsure how often it gets used, and probably doesn’t work any more)
* **admin_www/**: contains a small admin interface which the *animetosho.org* webserver proxies to. This can be exposed to moderators
* **includes/**: scripts included by others
  * **config.php**: some config values like database connection details. Note that some config items are defined in various other files
  * **c-\*.php**: these are ‘core’ cron scripts. A number of cron-\*.php scripts are actually just stubs that reference these files
  * **miniwebcore.php**: contains functionality from the website/frontend script, mostly for generating website URLs/links
  * **releasesrc_skip.php**: contains rules for what torrents don’t get processed
  * **resolve_custom.php**: custom rules used when matching torrents to AniDB anime/episode
  * **uploadhosts.php**: list of all DDL hosts to upload to with host-specific tuning values
  * **attach-info.php** and **filelinks.php**: contains functionality for packing/unpacking file attachment info and file DDL links respectively
  * **finfo-compress.php**: functionality to compress/decompress file info data using Zstd
  * **zstd-dict/**: preset dictionaries used for Zstd compression
* **news/**: holds the Usenet repost stuff; if the Usenet uploader fails to make some posts, the posts are dumped out, and this script tries to repost them
* **releasesrc/**: interfaces to source/upstream sites
* **schema/**: MySQL table schemas for each database
* **support/**: additional helper scripts
* **uploaders/**: interfaces to DDL sites
  * **baseclass.php**: core DDL uploader component which site-specific uploaders inherit
  * **filehandler\*.php**: wrapper around a file that will be uploaded; this is useful for wrapping files in a 7z archive as they are uploaded
  * **filehasher.php**: hashing interface (file hashes are computed during the upload process)
* **logs/**: logs and dump files are written here (dump files contain data when unknown errors are encountered, to help diagnose issues), so must be writable. The cron scripts also write lock files here to ensure that only one process is active. Log files are prefixed with *error*, *info* or *log*, with the latter being separated by month. Lock files have a *lock* prefix.

### Summary by DB Table

Schemas for MariaDB tables can be found in the *schema/* folder, where each .sql file corresponds with a database.

#### *toto_repl* Database

This database holds AT’s main data, which is replicated to the frontend servers, i.e. user-visible data.

* **toto_toto**: the main Anime Tosho listing of torrents. Entries are created by the *Torrent Fetching* task, and updated by various others
* **toto_trackers**: a list of observed torrent trackers
* **toto_tracker_stats**: tracker stats (seeders/leechers/completed) for torrents in the *toto_toto* table, referencing *toto_trackers*. Mostly populated by the *Torrent Stat Scraping* task, although the *Torrent Completion* task can populate this from Transmission data
* **toto_files**: lists files for each torrent in *toto_toto*. Entries are created by the *Torrent Completion* task
* **toto_fileinfo**: stores textual file info (e.g. Mediainfo) for *toto_files* entries in a packed form. Created by the *File Info Processing* task
* **toto_filelinks**: DDL links for files in a packed form. Added by the *DDL Uploading* task
* **toto_attachments**: lists extracted attachments for each file in a packed form. As attachments can be duplicated across files, this table refers to unique files referenced in *toto_attachment_files*. Entries here are created by the *File Info Processing* task.
* **toto_attachment_files**: lists unique file attachments, referenced by *toto_attachments*. An entry in this table corresponds to an on-disk file
* **toto_anidb_tvdb**: a AniDB to TVDB mapping, used for displaying a TVDB link on some series pages. This is maintained by the *TVDB Matching* task

##### Packed Fields

Some fields are compressed to reduce database size and improve memory caching efficiency. Compression is done via Zstd (magic stripped) with preset dictionaries (found in *includes/zstd-dict*), and serialization (if applicable) done using MsgPack. You can use the *admin-dump-finfo.php* script to view the contents of packed fields.

#### *toto* Database

This database holds data for keeping track of processing, and isn't forwarded to the website servers. A number of tables are used as task queues, which are consumed by their respective processing script.

* **toto_torrents**: corresponds with torrents added to Transmission for holding application related data on torrents. Added by the *Torrent Fetching* task, and queried/removed by the *Torrent Completion* task
* **toto_skip_fetch**: lists torrents that are not to be fetched. When fetching torrents, the script skips torrents that have already been added, but those that were completely skipped won't be listed in *toto_repl.toto_toto*. This table maintains a list so that the script doesn't have to keep rechecking these torrents.
* **toto_toarchive**: lists completed torrents where the script has decided to archive some of its files prior to uploading. This table holds a queue of torrents to be archived. Added to by the *Torrent Completion* task and entries processed by the *includes/c-archiving.php* script (currently invoked by one of the *File Info Processing* tasks)
* **toto_finfo**: a queue of files to be processed by the *File Info Processing* task. Added to by the *Torrent Completion* task
* **toto_ulqueue**: a queue of files to be processed by the *DDL Uploading* task. Added to by the *Torrent Completion* task
* **toto_ulqueue_failures**: when a DDL upload fails, an entry is added here. This is used to detect if many failures have occurred for a particular DDL host, and suspend uploads to that host for some time. Managed by the *DDL Uploading* task
* **toto_ulqueue_status**: current state of DDL uploaders. Used for displaying this info in the admin UI. Managed by the *DDL Uploading* task
* **toto_ulhosts**: records stats on DDL hosts. Not used by the script, except for the *defer* property, which needs to manually be set
* **toto_ulservers**: records stats on DDL host servers. Servers are based on the DNS that the uploader sends files to. If many uploads to a particular server have failed, the script can pick another one from this list to try with. An admin can also mark the *dead* field for a server to discourage the script from using that server
* **toto_ulserver_failures**: when a DDL upload fails, an entry is recorded for the DDL server here. It is used to detect if many failures have occurred to a particular server in a short amount of time, so that it can try other servers instead
* **toto_uploader_accounts**: if a DDL host needs an account to upload, details can be stored here. This table is manually managed and read by the *DDL Uploading* task
* **toto_tracker_scrape_failures**: a log of failures when trying to send scrape requests to torrent trackers. Used to hold off on scraping trackers with many failures
* **toto_adb_resolve_queue**: a queue of torrents to be processed by the *AniDB Tagging* task. Added to by the *Torrent Fetching* task
* **toto_adb_aniname_map**: a mapping of canonical titles to AniDB anime IDs. When trying to associate a torrent to an AniDB anime, the script will try to extract the canonical title from the torrent’s name, then do a lookup on this table. If a match isn't found, it will proceed to do a full search against AniDB titles.
  The *AniDB Tagging* task adds entries to this table, avoiding the slow full AniDB search process. This mapping table can also be used to manually force certain titles to match against specific AniDB anime IDs. The *admin-set-aniname.php* script can be used to add/remove an entry here
* **toto_adb_aniname_alias**: a mapping of titles to AniDB anime entries. AniDB sometimes lacks alternative titles; specifying them here makes the script treat these as alternative titles. This table is completely manually maintained, and an entry can be added via the *admin-set-anialias.php* script
* **toto_fiqueue**: a queue of torrents to be processed by the *File Info Uplaoding* task. Added to by the *File Info Processing* task
* **toto_filelinks_active**: a cache of unpacked data from *toto_repl.toto_filelinks*. The cache allows for faster lookups, but only retains recent data. Added by the *DDL Uploading* task, and old entries pruned by the *Active File Links Pruning* task.
* **toto_newsqueue**: a queue of torrents to be processed by the *Usenet Preparation* then *Usenet Uploading* tasks. Added to by the *Torrent Completion* task
* **toto_btih_seen**: a list of BitTorrent Info Hashes pulled from historic NyaaTorrents' data. This is used to identify old torrents that don’t exist in the *toto_repl.toto_toto* table and avoid processing re-posts
* **toto_src_cache_\***: when trying to find a source article for a torrent (e.g. if posted on a blog), a number of requests may be made to the indicated ‘website’ of the torrent. These tables cache data retrieved from the website to reduce the number of requests sent there. Managed by the *AniDB Tagging* task
* **toto_files_extra**: holds extra data on *toto_repl.toto_files* entries. Having this as a separate table avoids the data being replicated to the webservers, reducing the database size on those servers. Added to by the *File Info Processing* task.

#### *arcscrape* Database

This database holds data scraped from source sites. Populated by the *Source Site Mirroring* task and queried by the *Torrent Fetching* task. Currently only *nyaasi\** data is forwarded to the webserver to serve cache.animetosho.org

* **_state**: tracks the mirroring progress for each source site. Note that mirroring includes an initial pass, plus four subsequent ‘update’ passes to check for upstream changes
* **anidex_\***, **nekobt_\***, **nyaasi_\***, **tosho_\***: these tables hold data pulled from source sites

#### *anidb* Database

This database holds data pulled from AniDB. It is populated by the [AniDB TCP Client](https://github.com/animetosho/anidb-tcp-client) and [HTTP Client](https://github.com/animetosho/anidb-http-client), and only read by these scripts here. This database is forwarded to the web/feed servers.

### Processing Tasks

You can consider these scripts as performing a number of distinctive tasks.

#### Source Site Mirroring

This process scrapes source sites (Nyaa.si, TokyoTosho and nekoBT) and writes scraped data to the *arcscrape* database, as well as fetch the torrent file (not done for TokyoTosho). Note that this tries to scrape all entries, and runs update passes to try to detect changes made after initial posting (such as edits or removals). This is done by first fetching new entries (those with IDs higher than the existing highest ID), then running four separate time-delayed ‘update’ passes to incorporate changes.

This task is triggered by *cron-arcscrape.php*, predominantly pulling functionality from *includes/arcscrape.php* and *releasesrc/\**.

#### Torrent Fetching

This process takes new torrents from the *arcscrape* database and sets up entries on the Anime Tosho side (*toto\_repl.toto\_toto* table) and adds torrents to Transmission for downloading (with a corresponding *toto.toto_torrents* DB record). This process is triggered by *cron.php*, which manages a last-processed timestamp in *timebase.txt*

The various `*_run_from_scrape` functions in *releasesrc/* are run, which determine appropriate torrents, based on metadata such as category, and adds them via the `releasesrc_add` function in *includes/releasesrc.php*. The *includes/releasesrc_skip.php* file is also consulted for its list of skipping rules.

#### Torrent Completion

This process looks for newly completed torrents in Transmission and creates entries for these in a number of queues to be processed further. This also creates entries in the *toto\_repl.toto\_files* table, as well as hardlinks to the torrent files in the scratch space (*/atdata/\**) for subsequent tasks (which remove these when the respective task completes).

The process also cleans up torrents which have finished seeding, as well as ‘dead’ torrents (those which haven’t completed in a long time).

Triggered by *cron-complete.php*, which is a stub for *includes/c-complete.php*, and includes a lot of functionality in *includes/complete.php*
Torrent cleanup is handled in *includes/c-cleanup.php*.

#### DDL Uploading

This process takes files listed in *toto.toto_ulqueue*, stored in */atdata/ulqueue/file_\**, and uploads them to DDL hosts, inserting links into *toto.toto_filelinks_active* and *toto_repl.toto_filelinks*. This task also calculates hashes of files - doing it here avoids an additional I/O pass on files in order to hash them.
Triggered by *cron-ulqueue.sh*, which spawns several *cron-ulqueue\*.php* processes. These are all stubs for the core script, *includes/c-ulqueue.php*, which also references *includes/ulfuncs.php*. DDL host interfaces in *uploaders/* is used for uploading, and the list of DDL hosts are listed in *includes/uploadhosts.php*.

Files that fail an upload are retried. If many uploads to a host fail, further uploads to that host are deferred.

#### DDL Link Resolving

Multi-uploader hosts can take a while to generate links, thus this component of DDL processing is split off from above. Links that are marked as unresolved are processed by this script until they are resolved.
Triggered by *cron-linkresolv.php* and refers to DDL host interfaces in *uploaders/* to query upstreams.

#### Active File Links Pruning

All file links are stored in a packed format in *toto_repl.toto_filelinks*. The packed format reduces data size, but makes lookups more difficult, so an unpacked copy is stored in *toto.toto_filelinks_active*. Only recent entries are kept unpacked, and this script prunes older entries in order to keep this table small.
Triggered by *cron-prune.php*

#### Usenet Preparation

This process takes torrents listed in *toto.toto_newsqueue*, stored in */atdata/news*, and generates PAR2 files, and 7z archives any files in a subdirectory. Once complete, the torrent in the queue is marked eligible for uploading in the next task.
Triggered by *cron-newsprep.php*, which is a stub for *includes/c-newsprep.php*

#### Usenet Uploading

This process takes torrents listed in *toto.toto_newsqueue* (that have been prepared in the above task) and posts them to Usenet. Items which fail to be uploaded are left in a 'stuck' state in the queue and need to be manually fixed (usually by reverting the status to ‘ready to upload’).
Triggered by *cron-news.sh*, which spawns stubs *cron-news\*.php* backed by *includes/c-news.php*

#### Usenet Reposting

Articles that failed upload in the above process can get retried here (note, this is when the overall upload works, but specific articles fail). Failed articles are read from */atdata/nntpdump*; articles that fail to be posted after five days are moved to */atdata/nntpdump-fail*
Triggered by *news/repost.sh*

#### AniDB Tagging

This process takes torrents listed in *toto.toto_adb_resolve_queue* and tries to associate them with AniDB data (anime/episode/file/group).
Triggered by *cron-adb.php*, with much of the relevant logic in *anifuncs.php*. Some custom rules are listed in *includes/resolve_custom.php*

The process mostly tries to extract relevant data (title, episode and group) from the torrent's title (`parse_filename` function in *anifuncs.php*), then tries to match this with AniDB anime titles (`anidb_search_anime` function). You can use the *test-parsefilename.php* script to view the results of the title parsing, and *test-adb-search.php* to see how it tries to match an extracted title against AniDB data.
After matching the series, it will try to match the episode, group and file.

You can manually add a torrent to the queue (or prioritise it) via *admin-readd-resolv.php*, or use *admin-do-resolv.sh* to manually trigger this process on specified torrents.

AniDB data is synced in the *AniDB Data Mirroring* task.

If the torrent was posted with an associated ‘website’, this process also checks the website to find if a blog post is associated (*includes/find_src.php*).

#### Torrent Metadata Updating

Torrents may be updated or deleted since their initial posting. This process periodically rechecks the scraped data (*arcscrape* database) with what’s in *toto_repl.toto_toto* table and updates the latter if necessary.
Triggered by *cron-automod.php*

You can manually trigger this process for a torrent using *admin-automod.php*

#### Torrent Stat Scraping

This updates the seeders/leechers/completed counts for torrents (*toto_repl.toto_tracker_stats* table) by scraping listed trackers.
Triggered by *cron-scrape.php*, which references functionality in *includes/scrape.php*

#### File Info Processing

This process takes files listed in *toto.toto_finfo*, stored in */atdata/finfo*, and extracts info on them (via tools like Mediainfo) as well as data such as subtitles, video I-frames etc. This mostly updates the *toto_repl.toto_fileinfo* table and some fields of *toto_repl.toto_files*.
Triggered by *cron-finfo.sh* which spawns multiple processes derived off *includes/c-finfo.php*. Most functionality is found in *includes/finfo.php*. Processing may involve calling helper scripts *proc_img.sh* and *xzattach.sh* in the *includes/* folder.

The *cron-finfo1.php* script also triggers a File Archiving subtask (*includes/c-archiving.php*) which creates 7z archives when a torrent contains many small files. The archiving reduces the number of links a user needs to download over DDL with such torrents.

#### File Info Uploading

If the *File Info Processing* task needs to upload extracted files, it adds entries to *toto.toto_fiqueue* and hardlinks in */atdata/ulqueue/fileinfo_\**, and this process uploads them. Currently it is mostly used for uploading audio extracts to DDL hosts.
Triggered by *cron-fiqueue.sh* which spawns multiple uploaders derived off *includes/c-fiqueue.php*. The audio extract uploading reuses functionality of the *DDL Uploading* task.

#### TVDB Matching

Anime Tosho tags torrents based on AniDB data, which also links to various other anime info databases (‘resources’). AniDB doesn’t support TVDB as a resource, so this process tries to establish a mapping.
Triggered by *cron-tvdb.php*

This process just relies on [mappings provided by Anime-Lists](https://github.com/Anime-Lists/anime-lists.git) and imports those into the *toto_repl.toto_anidb_tvdb* table.

#### Transmission Adjustment

Monitors torrents in Transmission and adjusts upload limits. This is mostly to manage the upload bandwidth to prioritise DDL uploads and avoid seeding too much on dead torrents. If there are many DDL uploads active, this will turn on Transmission’s global upload limit, and turn it off when few uploads are active.
Triggered by *support/trans-ullimit.php*

#### Log Pruning

Periodically prunes old logs/dumps from the *logs/* folder.
Triggered by *support/cleanlogs.sh*

#### AniDB Data Mirroring

This isn’t included in this repository, but is a task that is periodically run.  See the [AniDB TCP Client](https://github.com/animetosho/anidb-tcp-client) and [AniDB HTTP Client](https://github.com/animetosho/anidb-http-client) repos for more details.

Data mirrored here is mostly consumed by the *AniDB Tagging* task, and displayed on the website.

### Other Functionality

#### Admin UI

The *admin\_www* folder contains a small admin UI, enabling moderators to view the current upload queue and push/delete torrents. This is served by a webserver and the primary webserver proxies to it.

#### Cloudscraper

Several websites sit behind CloudFlare. The Cloudscraper NodeJS module is used to solve non-CAPTCHA pages that CloudFlare sometimes throws. It is implemented as a HTTP proxy server where requests denied by CloudFlare are routed through.

It hasn’t been updated in a long time, so may no longer work properly.

### Processing Scratch Space

On disk, the */atdata* directory holds ‘temporary’ files used whilst processing a torrent. The directory has several subdirectories, which correspond with tasks that need a file input. Tasks remove these files once they complete successfully

* **finfo**: files queued up for the *File Info Processing* task are held here
* **news**: torrents queued up for the *Usenet* tasks
* **nntpdump**: when uploading to Usenet, if some articles fail to be accepted by the server, they are dumped here and the *Usenet Reposting* task tries to submit them later
* **nntpdump-fail**: if the *Usenet Reposting* task fails to submit an article after several tries, it is moved here, and needs to be manually cleaned up
* **torrents**: where Transmission holds torrents it is downloading/seeding
* **ulqueue**: holds files to be uploaded to DDL hosts in *DDL Uploading* and *File Info Uploading* tasks

## Setup

At a basic level, this requires PHP 7+ command line and a MariaDB server, with the following PHP extensions:

* mysqli
* xml
* bcmath
* ctype
* curl
* fileinfo
* GD
* iconv
* mbstring
* [msgpack](https://github.com/msgpack/msgpack-php)
* [openssl-incremental](https://github.com/zingaburga/php-openssl-incremental)
* posix
* sockets
* sysvsem
* tidy
* [zstd](https://github.com/kjdev/php-ext-zstd)
* [uploader extension](https://github.com/animetosho/uploader-php-ext)

However the scripts interact with many utilities with specific setups. See the [setup guide](https://github.com/animetosho/animetosho-setup) for Ansible scripts to configure the server correctly.

You will also need to create tables in the *arcscrape*, *toto* and *toto_repl* MariaDB databases by importing the respective *schema/* SQL files.

### Config

You should probably edit the following files to configure the script to your setup:

* includes/config.php
  * enter DB and Transmission connection details
* includes/c-news.php
  * enter Usenet server/login details in `$nyuuOpts`
* news/nyuu-repost.json
  * enter Usenet server/login details
* includes/miniwebcore.php
  * change the `HOME_URL` define

### Initial State

If you’re not importing data, load the *schema/arcscrape_init.sql* script into the *arcscrape* database. Note that this triggers the *Source Site Mirroring* process to start from the beginning (i.e. tries to mirror the whole upstream site) which can take a *very* long time. If you don’t want to start from the beginning, edit the entries in the *arcscrape._state* table to point to the ID you want to start mirroring from, noting that the *\*_upto* entries refer to the ID to start scraping from and *\*_upto_upd\** entries being the ID where the four update passes start pulling from.

Also, if you’re not importing AniDB data from elsewhere, load *schema/anidb_data.sql* into the *anidb* database, which populates the *cat* table, required by the *AniDB Tagging* task.

## Maintenance

Whilst this is designed to be a mostly automated system, there are maintenance tasks that need to be done for ideal operation. These include:

* monitoring queues to identify processing issues
* monitoring error logs
* changing scripts to fix issues, or adopt to changes (e.g. DDL host changes)
* manual fixes or adjustments
* improvements to address ongoing issues

### Helper Scripts

Various *admin-\*.php* scripts exist to help with some maintenance tasks. These need to be manually executed. A number of these need a specified torrent as an argument, which is referenced by site ID (e.g. ‘n1234’ for Nyaa’s ID=1234 entry) that can be found in AT’s view URLs (e.g. animetosho.org/view/[name].[site ID]).

* **admin-add-\*.php**: force a torrent to be added/fetched
* **admin-\*-update.php**: update *arcscrape* data for a specified torrent
* **admin-auto-resolv.php**: try to match a torrent against AniDB data by doing a lookup on the ED2K hash
* **admin-automod.php**: manual invocation of the *Torrent Metadata Updating* task for specified torrent
* **admin-check-skip.php**: check if a torrent would be skipped by the rules in *includes/releasesrc_skip.php*
* **admin-dump-finfo.php**: some data is stored in compressed form in the DB, this script shows such data uncompressed
* **admin-dump-transrpc.php**: shows torrent data from Transmission’s RPC endpoint
* **admin-find-attachfile-ref.php**: finds files (*toto_files* ID) that reference an attachment file (*toto_attachment_files* entry)
* **admin-id.php**: converts a site ID to a *toto_toto* ID
* **admin-rid.php**: converts a *toto_toto.id* to a site ID
* **admin-nekobt-idts.php**: converts a nekoBT ID to a timestamp or vice versa
* **admin-readd-resolv.php**: re-add a torrent to the *AniDB Tagging* task queue, or increase priority if it’s already there
* **admin-redo-fileinfo.php**: re-add a file to the *File Info Processing* task queue and removes some existing file info
* **admin-regen-flinkpack.php**: re-generate *toto_filelinks* from *toto_filelinks_active* for specified files
* **admin-resolv.php**: manually tag a torrent with specified AniDB IDs
* **admin-set-aniname.php**: associate a canonical anime title with an AniDB anime ID
* **admin-set-anialias.php**: associate an anime title with an AniDB anime ID
* **admin-src-changes.php**: list detected changes from source sites against *arcscrape* data. During the *Source Site Mirroring* task, the latest entries are fetched (usually via a feed) and compared against data in the *arcscrape* database to detect entries which have changed. This script shows what changes the process would identify

The following scripts I haven’t used in a long time, so they may be out of date and might no longer work properly - they should be reviewed before you use them:

* **admin-redo-torrent.php**: delete info for a processed torrent and reprocess it by fetching the torrent again
* **admin-fix-fileinfo.php**: re-run *File Info Processing* task. The *admin-redo-fileinfo.php* script probably does a better job
* **admin-regen-finfopack.php**: recompress some packed fields
* **admin-fix-sframesubs.php**: re-generate subtitle images dumped by *File Info Processing* task
* **admin-redo-findsrc.php**: re-run the blog post identification process in the *AniDB Tagging* task
* **admin-ress.php**: re-generate screenshots
* **admin-swapdupe.php**: when a duplicate torrent is encountered (e.g. uploaded to both Nyaa and nekoBT), one of them is marked as a duplicate. This script switches which is marked as a dupe
* **admin-torrent-tbl.php**: if a torrent in Transmission is missing in *toto_torrents*, try to re-add it

