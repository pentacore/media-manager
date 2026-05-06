# OLD/BLOCKED
- [ ] The AI Price Refresh should queue up a job to run in the background
    - [x] To begin with, we can just queue up a job and have it run in the background (Somehow we need the frontend to know if the job is done or not, so we cant queue up multiple jobs if one is already running, and we can show a loading state on the refresh button while the job is running)
    - [ ] (Awaiting support in the prisma-php/prisma package for batching) maybe make use of batching if the model supports it (Then we will also need a job that regularly checks the job status, and calls any tools it requests and then posts the updated data), also allow setting a specific model to use for this functionality.
# NEW
- [x] The "Top users" section in the watch history shows the wrong user (Looks like its always the first user in the list)
- [x] The "Watched" column in the watch history shows the wrong data, always shows the full length
- [x] Total watchtime doesnt seem to show the correct data either
- [ ] Make use of inertia pre-fetching to speed up page loading times (This will require some changes to the way we load data for the pages, but it should be worth it in the end)
- [x] In the AI assistant page, make the display of the chat title longer, i can only see 2 words as it currently is, and show the entire title on hover
- [x] For system entries in the AI usage page, it currently displays as "(S) --", so an avatar ball with an S, and ten just a long dash. say system instead oif the dash
- [ ] The free usage tracking should be defined as pools, since multiple models can share the same pool
- [ ] All AI related pages should be hidden if AI is disabled, not just the chat
- [ ] Allow adding a link to the "Free usage pools", that sends the user to the page documenting the free usage (External page).
- [ ] In addition to "Today", also add "This week", "This month", "This year" and "All" filters to all tables with this kind of time-scale filtering (AI usage, watch history, etc)
