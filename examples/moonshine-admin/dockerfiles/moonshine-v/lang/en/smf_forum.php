<?php

/*
| Labels for the SMF REST API resources shown in the MoonShine V panel.
| Used by the generated resource classes via __('smf_forum.*') so the
| panel switches language together with the MoonShine locale switcher.
*/

return [
    // Group id 0 has no row in SMF's membergroups table.
    'no_group' => 'Regular members',
    // A board's id_parent of 0: it sits directly in its category.
    'no_parent' => 'None',
    // Shown when a member has no additional groups.
    'default_post_group' => '— (default group)',
    'resources' => [
        'members' => 'Members',
        'categories' => 'Categories',
        'boards' => 'Boards',
        'topics' => 'Topics',
        'groups' => 'Groups',
    ],

    'fields' => [
        'id' => 'Id',

        'member' => [
            'username' => 'Username',
            'email' => 'Email',
            'password' => 'Password',
            'name' => 'Name',
            'posts' => 'Posts',
            'date_registered' => 'Date Registered',
            'last_login' => 'Last Login',
            'primary_group' => 'Primary Group',
            'post_group' => 'Post Group',
            'additional_groups' => 'Additional groups',
        ],

        'category' => [
            'name' => 'Name',
            'description' => 'Description',
            'order' => 'Order',
        ],

        'board' => [
            'category' => 'Category',
            'parent' => 'Parent board',
            'name' => 'Name',
            'description' => 'Description',
            'num_topics' => 'Topics',
            'num_posts' => 'Posts',
            'order' => 'Order',
        ],

        'topic' => [
            'board' => 'Board',
            'subject' => 'Subject',
            'body' => 'Body',
            'poster_time' => 'Posted at',
            'num_replies' => 'Replies',
            'num_views' => 'Views',
            'locked' => 'Locked',
            'is_sticky' => 'Sticky',
            'approved' => 'Approved',
            'id_first_msg' => 'First message id',
            'id_last_msg' => 'Last message id',
        ],

        'group' => [
            'name' => 'Name',
            'description' => 'Description',
            'type' => 'Type',
            'min_posts' => 'Min posts',
            'max_messages' => 'Max messages',
            'color' => 'Color',
            'icons' => 'Icons',
            'hidden' => 'Hidden',
            'parent' => 'Parent group id',
            'tfa_required' => '2FA required',
        ],
    ],

    // SMF membergroup type (0 private, 1 protected, 2 requestable, 3 free)
    'group_type' => [
        '0' => 'Private',
        '1' => 'Protected',
        '2' => 'Requestable',
        '3' => 'Free',
    ],
];
