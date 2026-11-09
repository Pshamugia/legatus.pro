# Meta App Review resubmission — comment and Threads permissions

## What the November 4 review actually said

Meta marked the use cases for `pages_manage_engagement`, `pages_read_user_content`, `instagram_manage_comments`, `threads_basic`, and `threads_content_publish` as allowed, but rejected all five because the submitted screencasts did not demonstrate the end-to-end experience described in the submission notes. This is a recording/evidence rejection, not evidence that production permissions or the feature implementation work. Approval cannot be guaranteed.

Do not resubmit the same recordings. Do not claim a successful live flow until it has been observed with the test Page, linked Instagram Professional account, and Threads profile used in the recording.

## Recording setup

1. Use a dedicated Legatus test business and Meta accounts with the appropriate app/test roles. Avoid disconnecting a live customer's existing channel merely to replay OAuth. Prepare a Page, its linked Instagram Professional account, a Threads profile, and a separate commenter account that can post test comments.
2. Use English for the Legatus and Meta UI during recording. Add visible English callouts explaining important buttons and transitions. Hide passwords, access tokens, personal messages, and unrelated customer records. Do not edit a recording to imply that an action occurred when it did not.
3. Start each permission's evidence at the Legatus sign-in and **Channels** screen. Show **Connect with Meta** or **Connect Threads**, the actual Meta authorization and permission grant, any Page/account selection, the return to Legatus, and the connected account. A previously connected account alone does not demonstrate the grant.
4. Then demonstrate the requested permission's actual use, from a test customer's action through the observable result. Keep the Meta surface and Legatus surface identifiable. The final recording must match the corresponding permission's written use-case notes, not merely tour the UI.
5. If a recording uses a system-user token or server-to-server connection instead of visible frontend authorization, say so explicitly in that permission's submission notes and show the corresponding end-to-end server-to-server use. The repository's Facebook/Instagram and Threads connection controllers currently implement user OAuth, so do not select this explanation for those flows unless the recorded environment really differs.

## Permission-by-permission evidence matrix

| Permission | What to show after the OAuth grant | Legatus route / implementation | End proof |
| --- | --- | --- | --- |
| `pages_read_user_content` | From a separate test Facebook user, write a comment on the connected Page's post. Show the comment on Facebook, then the same comment arriving in the Legatus Inbox. | `/app/channels` → Meta OAuth; Page `feed` webhook; Graph comment-detail read; `/app/inbox`. | Matching comment text and Page/post context are visible in both places. |
| `pages_manage_engagement` | Reply to that Page comment from Legatus, either through the reviewed AI flow or an operator reply. | `/app/inbox`; Page comment reply via Graph `/{comment-id}/comments`. | The reply appears underneath the original comment on the Facebook Page, not only inside Legatus. |
| `instagram_manage_comments` | From a separate Instagram user, comment on a post belonging to the connected Instagram Professional account. Show it in Legatus Inbox and reply through Legatus. | `/app/channels` → Meta OAuth; Instagram `comments` webhook and Graph comment-detail read; `/app/inbox`; reply via `/{comment-id}/replies`. | The original comment and Legatus reply are visible on the Instagram post. |
| `threads_basic` | Connect the Threads profile through **Connect Threads** and grant access in the Threads authorization screen. | `/app/channels/threads/connect`; callback retrieves `id`, `username`, `name` and stores the connected profile. | The same connected Threads username appears in Legatus. |
| `threads_content_publish` | On that connected profile, create a real product schedule that includes Threads, allow a due post to publish, then open the Threads profile. | `/app/social-media`; scheduled `PublishSocialMediaPost`; `ThreadsClient` creates an image container and calls `/me/threads_publish`. | The published post's actual image/text is visible on Threads and its Legatus post state is published. A preview or queued row alone is insufficient. |

Facebook and Instagram may share one OAuth recording if the actual Meta grant, Page/Instagram selection, and both connected accounts are clearly visible. Each permission still needs its own clearly identified usage segment and matching submission note. Threads uses its own authorization flow.

## English submission-note drafts

Replace bracketed fields only with real, tested reviewer-access information. Keep each note aligned with its uploaded video and include timestamps after recording. Do not paste tokens or secrets.

### pages_read_user_content

> Legatus is a multi-business customer-support assistant. A business owner signs in at [review URL], opens Channels, and selects Connect with Meta. After granting access and selecting a Facebook Page, a customer comments on a Page post. Legatus receives the Page comment and, when required, retrieves its text and author through the Page Graph API so the business can see the customer request in its Inbox. Video: [timestamp for login/grant], [timestamp for Facebook comment], [timestamp for matching Legatus Inbox entry]. Reviewer test business/account: [instructions].

### pages_manage_engagement

> After the business owner connects a Page through the Meta authorization shown in the video, Legatus lets the business respond to a customer's Page comment. The video shows the original comment, the reply initiated from Legatus Inbox, and that reply appearing beneath the original comment on the Facebook Page. This permission is used for the public Page-comment reply, not for private Messenger messages. Video: [timestamp for login/grant], [timestamp for reply in Legatus], [timestamp for visible Page reply].

### instagram_manage_comments

> The owner connects a linked Instagram Professional account through Legatus Channels and the Meta authorization flow. A separate Instagram user comments on the business's post. Legatus shows the incoming comment in the Inbox, and the business replies through Legatus; the reply appears on the Instagram post. Video: [timestamp for login/grant], [timestamp for Instagram comment], [timestamp for Legatus Inbox], [timestamp for visible Instagram reply].

### threads_basic

> The business owner signs in to Legatus, opens Channels, chooses Connect Threads, grants the requested Threads permissions, and returns to Legatus. Legatus reads the authorized profile's ID and username so the business can confirm which Threads account will publish scheduled posts. Video: [timestamp for sign-in and grant], [timestamp for connected username in Legatus].

### threads_content_publish

> After connecting the Threads profile through the authorization flow shown in the video, the business creates a product-post schedule in Legatus Social Media with Threads selected. Legatus prepares the image and caption and publishes the due post to that authorized Threads profile. The video shows the schedule, the published result in Legatus, and the same image/text on the public Threads profile. Video: [timestamp for grant], [timestamp for schedule], [timestamp for published state], [timestamp for actual Threads post].

## Pre-submission acceptance checklist

- [ ] Obtain and compare the previous **Permission usage** descriptions and submitted videos; resolve every discrepancy rather than assuming these drafts match the previous submission.
- [ ] Verify the test accounts and reviewer access without exposing credentials in a recording or document committed to Git.
- [ ] Rehearse each flow with actual Meta/Threads callbacks, webhook processing, queue workers, and outbound publication. A local automated test is not proof of this live behavior.
- [ ] Confirm the Facebook Page `feed` webhook and the Instagram app-level `comments` webhook are subscribed in the actual Meta app; a connected account badge alone does not prove comment delivery.
- [ ] Record the full grant and the actual end result for all five permissions. Add English callouts and timestamped submission notes.
- [ ] Have a person who did not record the video follow the notes and locate every grant, input, action, and final result without verbal explanation.
- [ ] Only then press **Request again** and upload the new evidence. Verify Meta's final decision separately.
