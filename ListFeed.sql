-- 
-- SQL for List Feed Generator Extension
-- 
-- Table for storing the archive of feed items.
CREATE TABLE /*_*/listfeed_items (
    -- Owner feed name
    feed VARBINARY(255) NOT NULL default '',
    -- Item hash
    hash VARBINARY(32) NOT NULL default '',
    -- Item text
    text MEDIUMBLOB NOT NULL default '',
    -- Item title
    title MEDIUMBLOB NOT NULL default '',
    -- URL to article
    link MEDIUMBLOB NOT NULL default '',
    -- Item author name
    author VARBINARY(255) NOT NULL default '',
    -- Item created timestamp
    created VARBINARY(14),
    -- Item modify timestamp
    modified VARBINARY(14),
    -- Primary key
    PRIMARY KEY (feed, hash),
    -- Key for fetching items sorted by create time
    INDEX listfeed_items_feed_created (feed, created)
) /*$wgDBTableOptions*/;
