# OLD/BLOCKED
- [ ] The AI Price Refresh should queue up a job to run in the background
    - [x] To begin with, we can just queue up a job and have it run in the background (Somehow we need the frontend to know if the job is done or not, so we cant queue up multiple jobs if one is already running, and we can show a loading state on the refresh button while the job is running)
    - [ ] (Awaiting support in the prisma-php/prisma package for batching) maybe make use of batching if the model supports it (Then we will also need a job that regularly checks the job status, and calls any tools it requests and then posts the updated data), also allow setting a specific model to use for this functionality.
# NEW
- [ ] Make use of inertia pre-fetching to speed up page loading times (This will require some changes to the way we load data for the pages, but it should be worth it in the end)
- [ ] The free usage tracking should be defined as pools, since multiple models can share the same pool
- [ ] All AI related pages should be hidden if AI is disabled, not just the chat
- [ ] Allow adding a link to the "Free usage pools", that sends the user to the page documenting the free usage (External page).
- [ ] In addition to "Today", also add "This week", "This month", "This year" and "All" filters to all tables with this kind of time-scale filtering (AI usage, watch history, etc)
- [ ] Allow setting a models free usage pool to be daily, weekly or monthly
- [ ] Allow setting a models free usage pool input and output tokens to be a unified pool.
- [ ] Allow setting a models rate limit, some might have X amount of requests per day/hour/minute, or a limit on Tokens per minute/day etc
- [ ] Allow setting an external_url for a service, which is used when the user opens any links that points to the service in question.
- [ ] Show media images etc in the TV Series, Movies, Requests and search views, instead of just the title and year.
